<?php

declare(strict_types=1);

namespace App\Http\Search;

use App\Http\Pages\PageSupport;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Platform\Authorization\AreaReach;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Typeahead lookups for form fields (UX brief §4 "Lookups (policy, customer, agent): typeahead with number/name/phone … Ctrl+N to create
 * inline"): GET /lookup/{customer|agent|policy|installment|payee}?q=, POST /lookup/customer to create a customer inline and (flow fix X8)
 * POST /lookup/payee to create a claim payee. Read access follows the areas that use the field. Flow fix X9: POST /lookup/producer creates a
 * producer inline (party, producer on the form's branch, licence) through the Party and Distribution services, for holders of agent.manage;
 * GET /lookup/producer/new suggests its code. GA-17: customers are also found by mobile number and NID/BRN.
 */
final class LookupController
{
    private const LIMIT = 12;

    /** Flow fix X8: the duty that looks up and creates claim payees, checked on the claim's branch for creation — the same check as approving. */
    private const PAYEE_DUTY = 'claim.approve';

    private const PRODUCER_TYPES = ['agent', 'agency_org', 'bdo', 'broker', 'partner'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function search(Request $request, string $type): JsonResponse
    {
        $actor = PageSupport::actor($request);
        $like = '%'.addcslashes(trim((string) $request->query('q', '')), '%_\\').'%';
        $results = match ($type) {
            // Follow-up H1: lookups open for the area's permissions in any scope. Policies and installments are branch-bound and limited to the user's reach;
            // customers, producers and payees are parties of the whole tenant (ASSUMPTION A-160).
            'customer' => $this->guarded($actor, [...PartyPageController::AREA, ...PolicyPageController::AREA, 'claim.pay_request'], fn (): array => $this->customers($like)),
            'agent' => $this->guarded($actor, [...PartyPageController::AREA, ...CollectionsPageController::AREA], fn (): array => $this->agents($like)),
            'policy' => $this->guarded($actor, [...PolicyPageController::AREA, 'claim.register'], fn (AreaReach $reach): array => $this->policies($like, $reach)),
            'installment' => $this->guarded($actor, CollectionsPageController::AREA, fn (AreaReach $reach): array => $this->installments($like, $reach)),
            // Flow fix X8: whoever approves claim payments picks the payee from every active party.
            'payee' => $this->guarded($actor, [self::PAYEE_DUTY], fn (): array => $this->payees($like)),
            default => abort(404),
        };

        return response()->json(['results' => $results]);
    }

    public function createCustomer(Request $request, PartyService $parties): JsonResponse
    {
        /** @var array{kind: string, display_name: string, tax_id?: string|null} $data */
        $data = $request->validate(['kind' => ['required', Rule::enum(PartyKind::class)], 'display_name' => ['required', 'string', 'max:255'], 'tax_id' => ['nullable', 'string', 'max:64'],
            ...PartyPageController::contactRules()]);
        // GA-17: the drawer takes the mobile, email and NID/BRN too (the mobile is optional here, A-193).
        $contact = PartyPageController::contact($request, PartyKind::from($data['kind']), 'quote_drawer');
        $party = $parties->create(PartyKind::from($data['kind']), $data['display_name'], $data['tax_id'] ?? null, [PartyRoleType::Customer, PartyRoleType::Policyholder], PageSupport::actor($request), $contact);

        return response()->json(['result' => self::customer((string) $party->id, $party->display_name, $data['kind'], $data['tax_id'] ?? null, $party->mobile)], 201);
    }

    public function createPayee(Request $request, PartyService $parties): JsonResponse
    {
        /** @var array{claim_id: string, kind: string, display_name: string, tax_id?: string|null, role: string} $data */
        $data = $request->validate(['claim_id' => ['required', 'uuid'], 'kind' => ['required', Rule::enum(PartyKind::class)], 'display_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'], 'role' => ['required', Rule::in(array_map(fn (PartyRoleType $r): string => $r->value, PartyService::PAYEE_ROLES))]]);
        $claim = DB::table('claims')->where('id', $data['claim_id'])->first(['entity_id', 'branch_id']) ?? abort(404);
        $party = $parties->createPayee(PartyKind::from($data['kind']), $data['display_name'], $data['tax_id'] ?? null, PartyRoleType::from($data['role']), PageSupport::actor($request),
            self::PAYEE_DUTY, AuthorizationScope::branch((string) $claim->entity_id, (string) $claim->branch_id));

        return response()->json(['result' => ['id' => (string) $party->id, 'label' => $party->display_name, 'detail' => ucfirst($data['kind']).' · '.$data['role']]], 201);
    }

    /** Flow fix X9: the next free code for a producer type (erp.distribution.producer_code_prefixes: AG-001, BDO-004…) and today, for the create drawer. */
    public function newProducer(Request $request): JsonResponse
    {
        $this->permissions->authorize(PageSupport::actor($request), 'agent.manage');
        $type = in_array($request->query('type'), self::PRODUCER_TYPES, true) ? (string) $request->query('type') : 'agent';

        return response()->json(['code' => self::suggestedCode($type), 'today' => app(BusinessClock::class)->today()->toDateString()]);
    }

    /**
     * Flow fix X9 (Part A step 1): a producer created from the quote — the party (an individual agent or BDO, otherwise an organisation), the producer on the
     * quote's branch from today and its licence, in one transaction, each through its own service (permissions, rules and audit as on the producer pages).
     */
    public function createProducer(Request $request, PartyService $parties, ProducerService $producers, LicenceService $licences): JsonResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, 'agent.manage');
        $this->permissions->authorize($actor, 'party.manage');
        /** @var array{name: string, producer_type: string, code: string, branch_id: string, licence_no: string, licence_class: string, issued_on: string, expires_on: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'producer_type' => ['required', Rule::in(self::PRODUCER_TYPES)],
            'code' => ['required', 'string', 'max:32', Rule::unique('producers', 'code')], 'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'licence_no' => ['required', 'string', 'max:64', Rule::unique('producer_licences', 'licence_no')->where('authority', 'IDRA')],
            'licence_class' => ['required', Rule::in(LicenceService::CLASSES)], 'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'after_or_equal:issued_on'],
        ], [
            'name.required' => 'Enter the producer\'s name.', 'code.unique' => 'Another producer has this code.', 'branch_id.required' => 'Choose the branch on the quote first.',
            'licence_no.required' => 'Enter the licence number: a producer writing new business needs a valid licence.', 'licence_no.unique' => 'This licence number is already recorded.',
            'issued_on.before_or_equal' => 'A licence cannot be issued in the future.', 'expires_on.after_or_equal' => 'The licence must be valid today to write new business.',
        ]);
        $kind = in_array($data['producer_type'], ['agent', 'bdo'], true) ? PartyKind::Individual : PartyKind::Organization;
        $role = $data['producer_type'] === 'bdo' ? PartyRoleType::Employee : PartyRoleType::Agent;

        $producer = DB::transaction(function () use ($data, $kind, $role, $actor, $parties, $producers, $licences) {
            $party = $parties->create($kind, trim($data['name']), null, [$role], $actor);
            $producer = $producers->create(new CreateProducer((string) $party->id, trim($data['code']), $data['producer_type'], $data['branch_id']), $actor);
            $licences->record(new RecordLicence($producer->id, trim($data['licence_no']), $data['licence_class'], CarbonImmutable::parse($data['issued_on']),
                CarbonImmutable::parse($data['expires_on'])), $actor);

            return $producer;
        });

        return response()->json(['result' => ['id' => $producer->id, 'label' => "{$producer->code} · ".trim($data['name']), 'detail' => 'Agent']], 201);
    }

    private static function suggestedCode(string $type): string
    {
        /** @var array<string, string> $prefixes */
        $prefixes = (array) config('erp.distribution.producer_code_prefixes', []);
        $prefix = $prefixes[$type] ?? 'PR';
        $highest = 0;
        foreach (DB::table('producers')->where('code', 'like', addcslashes($prefix, '%_\\').'-%')->pluck('code') as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{1,9})$/', (string) $code, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $highest + 1);
    }

    /**
     * @param list<string> $area
     * @param callable(AreaReach): list<array<string, string>> $query
     * @return list<array<string, string>>
     */
    private function guarded(string $actor, array $area, callable $query): array
    {
        return $query($this->permissions->authorizeArea($actor, array_values(array_unique($area))));
    }

    /** @return list<array<string, string>> */
    private function customers(string $like): array
    {
        $rows = DB::table('parties')->whereExists(fn ($q) => $q->from('party_roles')->whereColumn('party_roles.party_id', 'parties.id')->whereIn('role', ['customer', 'policyholder']))
            // GA-17: a customer is also found by NID/BRN or mobile number (typed with or without the leading 0).
            ->where(fn ($q) => $q->where('display_name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like)->orWhere('identity_no', 'ilike', $like)->orWhere('mobile', 'ilike', '%'.ltrim(trim($like, '%'), '0').'%'))
            ->orderBy('display_name')->limit(self::LIMIT)->get(['id', 'display_name', 'kind', 'tax_id', 'mobile']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = self::customer((string) $row->id, (string) $row->display_name, (string) $row->kind, $row->tax_id === null ? null : (string) $row->tax_id, $row->mobile === null ? null : (string) $row->mobile);
        }

        return $results;
    }

    /** @return list<array<string, string>> */
    private function payees(string $like): array
    {
        $rows = DB::table('parties as p')->where('p.status', 'active')->where(fn ($q) => $q->where('p.display_name', 'ilike', $like)->orWhere('p.tax_id', 'ilike', $like))
            ->orderBy('p.display_name')->limit(self::LIMIT)
            ->get(['p.id', 'p.display_name', 'p.kind', DB::raw("(select string_agg(r.role, ', ' order by r.role) from party_roles r where r.party_id = p.id) as roles")]);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => (string) $row->id, 'label' => (string) $row->display_name, 'detail' => ucfirst((string) $row->kind).($row->roles !== null ? " · {$row->roles}" : '')];
        }

        return $results;
    }

    /** @return list<array<string, string>> */
    private function agents(string $like): array
    {
        $rows = DB::table('producers as a')->join('parties as p', 'p.id', '=', 'a.party_id')->where('a.status', 'active')
            ->where(fn ($q) => $q->where('a.code', 'ilike', $like)->orWhere('p.display_name', 'ilike', $like))->orderBy('a.code')->limit(self::LIMIT)->get(['a.id', 'a.code', 'p.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => (string) $row->id, 'label' => "{$row->code} · {$row->display_name}", 'detail' => 'Agent'];
        }

        return $results;
    }

    /** @return list<array<string, string>> */
    private function policies(string $like, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('policies as p'), 'p.entity_id', 'p.branch_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->whereNotNull('p.number')
            ->where(fn ($q) => $q->where('p.number', 'ilike', $like)->orWhere('h.display_name', 'ilike', $like))->orderByDesc('p.inception')->limit(self::LIMIT)
            ->get(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'h.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => (string) $row->id, 'label' => (string) $row->number, 'detail' => "{$row->display_name} · ".ucfirst((string) $row->status).' · cover '.self::day((string) $row->inception).' to '.self::day((string) $row->expiry)];
        }

        return $results;
    }

    /** @return list<array<string, string>> */
    private function installments(string $like, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('installments as i'), 'p.entity_id', 'p.branch_id')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
            ->whereIn('p.status', ['issued', 'active', 'lapsed', 'expired'])->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->where(fn ($q) => $q->where('p.number', 'ilike', $like)->orWhere('payer.display_name', 'ilike', $like))
            ->orderBy('p.number')->orderBy('i.no')->limit(self::LIMIT)
            ->get(['i.id', 'p.number', 'i.no', 'i.due_date', 'p.currency', 'payer.display_name', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        $results = [];
        foreach ($rows as $row) {
            $amount = PageSupport::money((int) $row->outstanding, (string) $row->currency);
            $results[] = ['id' => (string) $row->id, 'label' => "{$row->number} #{$row->no}", 'detail' => "{$row->display_name} · due ".self::day((string) $row->due_date)." · {$amount} outstanding", 'amount' => $amount];
        }

        return $results;
    }

    /** "2026-09-12" → "12 Sep 2026" (brief §4 date format). */
    private static function day(string $date): string
    {
        return \Carbon\CarbonImmutable::parse($date)->format('j M Y');
    }

    /** @return array{id: string, label: string, detail: string} */
    private static function customer(string $id, string $name, string $kind, ?string $taxId, ?string $mobile = null): array
    {
        return ['id' => $id, 'label' => $name, 'detail' => ucfirst($kind).($mobile !== null ? " · {$mobile}" : '').($taxId !== null ? " · TIN {$taxId}" : '')];
    }
}
