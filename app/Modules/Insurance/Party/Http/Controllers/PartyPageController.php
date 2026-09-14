<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Insurance\Party\Domain\Models\PartyBankAccount;
use App\Modules\Insurance\Party\Domain\PartyContact;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parties screens (spec §3 party model): list, search, create, edit, bank accounts and (GA-17) the customer's page — contact details, policies, claims,
 * receipts, documents and audit on one object page. Producers (agents) have their own register (GA-10).
 */
final class PartyPageController
{
    /** Anyone who works with parties: maintains them, sells to them, or reads the books. */
    public const AREA = ['party.manage', 'agent.manage', 'policy.create', 'reports.financial'];

    public const MANAGE = 'party.manage';

    public function __construct(
        private readonly PartyService $parties,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $search = trim((string) $request->query('search', ''));
        $mobile = self::mobileSearch($search);
        $page = Party::query()->with('roles')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('display_name ilike ?', ["%{$search}%"])->orWhere('tax_id', $search)->orWhere('identity_no', $search)
                ->when($mobile !== null, fn ($m) => $m->orWhere('mobile', $mobile))))
            ->orderBy('display_name')->paginate(PageSupport::listPageSize())->withQueryString();

        return Inertia::render('parties/Index', [
            'search' => $search,
            'parties' => PageSupport::page($page, array_map(fn (Party $p): array => self::summary($p), $page->items())),
            'kinds' => array_column(PartyKind::cases(), 'value'),
            'roles' => array_column(PartyRoleType::cases(), 'value'),
            'mobileRequired' => self::mobileRequired('party_form'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{kind: string, display_name: string, tax_id?: string|null, roles: list<string>} $data */
        $data = $request->validate(['kind' => ['required', Rule::enum(PartyKind::class)], 'display_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'], 'roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::enum(PartyRoleType::class)], ...self::contactRules()]);
        $kind = PartyKind::from($data['kind']);
        $contact = self::contact($request, $kind, 'party_form');
        $party = $this->parties->create($kind, $data['display_name'], $data['tax_id'] ?? null,
            array_map(fn (string $role): PartyRoleType => PartyRoleType::from($role), $data['roles']), PageSupport::actor($request), $contact);

        return redirect("/parties/{$party->id}")->with('status', "Party {$party->display_name} created.");
    }

    /** GA-17: edit the name, tax ID and contact details (party.manage). The kind and roles stay as they are. */
    public function update(Request $request, string $party): RedirectResponse
    {
        $model = Party::query()->findOrFail($party);
        /** @var array{display_name: string, tax_id?: string|null} $data */
        $data = $request->validate(['display_name' => ['required', 'string', 'max:255'], 'tax_id' => ['nullable', 'string', 'max:64'], ...self::contactRules()]);
        $contact = self::contact($request, $model->kind, 'party_form');
        $this->parties->update($model->id, ['display_name' => $data['display_name'], 'tax_id' => ($data['tax_id'] ?? null) ?: null], null, PageSupport::actor($request), $contact);

        return redirect("/parties/{$model->id}")->with('status', 'Details saved.');
    }

    public function show(Request $request, string $party): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = Party::query()->with('roles')->findOrFail($party);
        $currency = PageSupport::entity()['currency'];
        $policyIds = DB::table('policies')->where('policyholder_party_id', $model->id)->pluck('id');
        $canManage = $this->permissions->has($actor, self::MANAGE);
        $subjects = [['party', $model->id]];
        $history = app(\App\Http\Pages\ObjectHistory::class);

        return Inertia::render('parties/Show', [
            'party' => [...self::summary($model), 'mobile' => $model->mobile, 'email' => $model->email, 'address' => $model->address, 'identity_no' => $model->identity_no,
                'date_of_birth' => $model->date_of_birth === null ? null : substr((string) $model->date_of_birth, 0, 10), 'contact_person' => $model->contact_person, 'status' => $model->status],
            'bankAccounts' => PartyBankAccount::query()->where('party_id', $model->id)->orderBy('created_at')->get()
                ->map(fn (PartyBankAccount $a): array => ['id' => $a->id, 'bank_name' => $a->bank_name, 'account_no_masked' => $a->account_no_masked, 'is_default' => (bool) $a->is_default])
                ->values()->all(),
            'policies' => DB::table('policies as p')->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')->where('p.policyholder_party_id', $model->id)->orderByDesc('p.inception')->limit(200)
                ->get(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'p.gross_premium_minor', 'p.currency', 'pr.name as product'])
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'number' => $p->number, 'status' => (string) $p->status, 'inception' => (string) $p->inception, 'expiry' => (string) $p->expiry,
                    'product' => (string) $p->product, 'gross_premium' => PageSupport::money((int) $p->gross_premium_minor, (string) $p->currency)])->values()->all(),
            'claims' => DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')
                ->where(fn ($q) => $q->whereIn('c.policy_id', $policyIds)->orWhereExists(fn ($e) => $e->from('claim_payments as cp')->whereColumn('cp.claim_id', 'c.id')->where('cp.payee_party_id', $model->id)))
                ->orderByDesc('c.reported_on')->limit(200)->get(['c.id', 'c.number', 'c.status', 'c.loss_date', 'c.reported_on', 'c.reserve_minor', 'c.currency', 'p.number as policy_number'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'number' => (string) $c->number, 'status' => (string) $c->status, 'loss_date' => (string) $c->loss_date,
                    'policy_number' => $c->policy_number, 'reserve' => PageSupport::money((int) $c->reserve_minor, (string) $c->currency)])->values()->all(),
            'receipts' => DB::table('receipts as r')
                ->where(fn ($q) => $q->where('r.party_id', $model->id)->orWhereExists(fn ($e) => $e->from('receipt_allocations as a')->whereColumn('a.receipt_id', 'r.id')->whereIn('a.policy_id', $policyIds)))
                ->orderByDesc('r.value_date')->limit(200)->get(['r.id', 'r.number', 'r.status', 'r.channel', 'r.value_date', 'r.amount_minor', 'r.currency'])
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'status' => (string) $r->status, 'channel' => (string) $r->channel,
                    'value_date' => (string) $r->value_date, 'amount' => PageSupport::money((int) $r->amount_minor, (string) $r->currency)])->values()->all(),
            'currency' => $currency,
            'kinds' => array_column(PartyKind::cases(), 'value'),
            'mobileRequired' => self::mobileRequired('party_form'),
            'can' => ['manage' => $canManage],
            'timeline' => $history->timeline($subjects),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => app(ObjectDocuments::class)->forPage('party', $model->id, "/parties/{$model->id}"), 'history'),
            'documentUpload' => $canManage ? "/parties/{$model->id}/documents" : null,
        ]);
    }

    public function storeBankAccount(Request $request, string $party): RedirectResponse
    {
        /** @var array{bank_name: string, account_number: string, is_default?: bool} $data */
        $data = $request->validate(['bank_name' => ['required', 'string', 'max:255'], 'account_number' => ['required', 'string', 'max:64'], 'is_default' => ['sometimes', 'boolean']]);
        $this->parties->addBankAccount($party, $data['bank_name'], $data['account_number'], (bool) ($data['is_default'] ?? false), PageSupport::actor($request));

        return redirect("/parties/{$party}")->with('status', 'Bank account added.');
    }

    /** GA-17: supporting documents on the customer (NID copy, trade licence), attached by whoever maintains parties. */
    public function attachDocument(Request $request, string $party, ObjectDocuments $documents): RedirectResponse
    {
        $this->permissions->authorize(PageSupport::actor($request), self::MANAGE);
        $model = Party::query()->findOrFail($party);

        return $documents->attach($request, 'party', $model->id, "/parties/{$model->id}");
    }

    public function downloadDocument(Request $request, string $party, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $model = Party::query()->findOrFail($party);

        return $documents->download($request, 'party', $model->id, $document);
    }

    /** @return array<string, list<mixed>> */
    public static function contactRules(): array
    {
        return ['mobile' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'string', 'max:254'], 'address' => ['nullable', 'string', 'max:1000'],
            'identity_no' => ['nullable', 'string', 'max:32'], 'date_of_birth' => ['nullable', 'date_format:Y-m-d'], 'contact_person' => ['nullable', 'string', 'max:255']];
    }

    /**
     * The contact details typed on a form, as field errors when they are not valid. ASSUMPTION: A-193 (GA-17) — an individual's mobile is required where
     * `erp.parties.mobile_required.<form>` says so (the Parties form yes, the quote's inline New customer drawer no).
     */
    public static function contact(Request $request, PartyKind $kind, string $form): PartyContact
    {
        /** @var array{mobile?: string|null, email?: string|null, address?: string|null, identity_no?: string|null, date_of_birth?: string|null, contact_person?: string|null} $input */
        $input = $request->only(['mobile', 'email', 'address', 'identity_no', 'date_of_birth', 'contact_person']);
        if ($kind === PartyKind::Individual && self::mobileRequired($form) && trim((string) ($input['mobile'] ?? '')) === '') {
            throw ValidationException::withMessages(['mobile' => 'Enter the mobile number: renewal and claim messages go to it.']);
        }
        try {
            return PartyContact::fromInput($kind, $input);
        } catch (\App\Modules\Platform\Exceptions\BusinessRuleViolation $e) {
            $field = match ($e->reasonCode) {
                'PARTY_MOBILE_INVALID' => 'mobile',
                'PARTY_EMAIL_INVALID' => 'email',
                default => 'date_of_birth',
            };
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    public static function mobileRequired(string $form): bool
    {
        return (bool) config("erp.parties.mobile_required.{$form}", false);
    }

    /** A search that looks like a mobile number finds the party by its stored +880… form. */
    private static function mobileSearch(string $search): ?string
    {
        if (preg_match('/^[+\d][\d\s\-]{7,}$/', $search) !== 1) {
            return null;
        }
        try {
            return PartyContact::mobile($search);
        } catch (\App\Modules\Platform\Exceptions\BusinessRuleViolation) {
            return null;
        }
    }

    /** @return array{id: string, kind: string, display_name: string, tax_id: string|null, roles: array<int, string>, mobile: string|null} */
    private static function summary(Party $party): array
    {
        return ['id' => $party->id, 'kind' => $party->kind->value, 'display_name' => $party->display_name, 'tax_id' => $party->tax_id, 'mobile' => $party->mobile,
            'roles' => array_values($party->roles->sortBy('id')->pluck('role')->map(fn (mixed $r): string => $r instanceof \BackedEnum ? (string) $r->value : (string) $r)->all())];
    }
}
