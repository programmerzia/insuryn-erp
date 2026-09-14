<?php

declare(strict_types=1);

namespace App\Http\Search;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Typeahead lookups for form fields (UX brief §4 "Lookups (policy, customer, agent): typeahead with number/name/phone … Ctrl+N to create
 * inline"): GET /lookup/{customer|agent|policy|installment|payee}?q=, POST /lookup/customer to create a customer inline and (flow fix X8)
 * POST /lookup/payee to create a claim payee. Read access follows the areas that use the field. Phone search is not possible: parties have no
 * phone number yet.
 */
final class LookupController
{
    private const LIMIT = 12;

    /** Flow fix X8: the duty that looks up and creates claim payees, checked on the claim's branch for creation — the same check as approving. */
    private const PAYEE_DUTY = 'claim.approve';

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function search(Request $request, string $type): JsonResponse
    {
        $actor = PageSupport::actor($request);
        $like = '%'.addcslashes(trim((string) $request->query('q', '')), '%_\\').'%';
        $results = match ($type) {
            'customer' => $this->guarded($actor, [...PartyPageController::AREA, ...PolicyPageController::AREA, 'claim.pay_request'], fn (): array => $this->customers($like)),
            'agent' => $this->guarded($actor, [...PartyPageController::AREA, ...CollectionsPageController::AREA], fn (): array => $this->agents($like)),
            'policy' => $this->guarded($actor, [...PolicyPageController::AREA, 'claim.register'], fn (): array => $this->policies($like)),
            'installment' => $this->guarded($actor, CollectionsPageController::AREA, fn (): array => $this->installments($like)),
            // Flow fix X8: whoever approves claim payments picks the payee from every active party.
            'payee' => $this->guarded($actor, [self::PAYEE_DUTY], fn (): array => $this->payees($like)),
            default => abort(404),
        };

        return response()->json(['results' => $results]);
    }

    public function createCustomer(Request $request, PartyService $parties): JsonResponse
    {
        /** @var array{kind: string, display_name: string, tax_id?: string|null} $data */
        $data = $request->validate(['kind' => ['required', Rule::enum(PartyKind::class)], 'display_name' => ['required', 'string', 'max:255'], 'tax_id' => ['nullable', 'string', 'max:64']]);
        $party = $parties->create(PartyKind::from($data['kind']), $data['display_name'], $data['tax_id'] ?? null, [PartyRoleType::Customer, PartyRoleType::Policyholder], PageSupport::actor($request));

        return response()->json(['result' => self::customer((string) $party->id, $party->display_name, $data['kind'], $data['tax_id'] ?? null)], 201);
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

    /**
     * @param list<string> $area
     * @param callable(): list<array<string, string>> $query
     * @return list<array<string, string>>
     */
    private function guarded(string $actor, array $area, callable $query): array
    {
        $this->permissions->authorizeAny($actor, array_values(array_unique($area)));

        return $query();
    }

    /** @return list<array<string, string>> */
    private function customers(string $like): array
    {
        $rows = DB::table('parties')->whereExists(fn ($q) => $q->from('party_roles')->whereColumn('party_roles.party_id', 'parties.id')->whereIn('role', ['customer', 'policyholder']))
            ->where(fn ($q) => $q->where('display_name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like))->orderBy('display_name')->limit(self::LIMIT)->get(['id', 'display_name', 'kind', 'tax_id']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = self::customer((string) $row->id, (string) $row->display_name, (string) $row->kind, $row->tax_id === null ? null : (string) $row->tax_id);
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
    private function policies(string $like): array
    {
        $rows = DB::table('policies as p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->whereNotNull('p.number')
            ->where(fn ($q) => $q->where('p.number', 'ilike', $like)->orWhere('h.display_name', 'ilike', $like))->orderByDesc('p.inception')->limit(self::LIMIT)
            ->get(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'h.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => (string) $row->id, 'label' => (string) $row->number, 'detail' => "{$row->display_name} · ".ucfirst((string) $row->status).' · cover '.self::day((string) $row->inception).' to '.self::day((string) $row->expiry)];
        }

        return $results;
    }

    /** @return list<array<string, string>> */
    private function installments(string $like): array
    {
        $rows = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
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
    private static function customer(string $id, string $name, string $kind, ?string $taxId): array
    {
        return ['id' => $id, 'label' => $name, 'detail' => ucfirst($kind).($taxId !== null ? " · TIN {$taxId}" : '')];
    }
}
