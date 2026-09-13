<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Insurance\Party\Domain\Models\PartyBankAccount;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Parties and agents screens (spec §3 party model): list, search, create, bank accounts, agents. */
final class PartyPageController
{
    /** Anyone who works with parties: maintains them, sells to them, or reads the books. */
    public const AREA = ['party.manage', 'agent.manage', 'policy.create', 'reports.financial'];

    public function __construct(
        private readonly PartyService $parties,
        private readonly AgentService $agents,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $search = trim((string) $request->query('search', ''));
        $page = Party::query()->with('roles')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('display_name ilike ?', ["%{$search}%"])->orWhere('tax_id', $search)))
            ->orderBy('display_name')->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();

        return Inertia::render('parties/Index', [
            'search' => $search,
            'parties' => PageSupport::page($page, array_map(fn (Party $p): array => self::summary($p), $page->items())),
            'kinds' => array_column(PartyKind::cases(), 'value'),
            'roles' => array_column(PartyRoleType::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{kind: string, display_name: string, tax_id?: string|null, roles: list<string>} $data */
        $data = $request->validate(['kind' => ['required', Rule::enum(PartyKind::class)], 'display_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'], 'roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::enum(PartyRoleType::class)]]);
        $party = $this->parties->create(PartyKind::from($data['kind']), $data['display_name'], $data['tax_id'] ?? null,
            array_map(fn (string $role): PartyRoleType => PartyRoleType::from($role), $data['roles']), PageSupport::actor($request));

        return redirect("/parties/{$party->id}")->with('status', "Party {$party->display_name} created.");
    }

    public function show(Request $request, string $party): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $model = Party::query()->with('roles')->findOrFail($party);

        return Inertia::render('parties/Show', [
            'party' => self::summary($model),
            'bankAccounts' => PartyBankAccount::query()->where('party_id', $model->id)->orderBy('created_at')->get()
                ->map(fn (PartyBankAccount $a): array => ['id' => $a->id, 'bank_name' => $a->bank_name, 'account_no_masked' => $a->account_no_masked, 'is_default' => (bool) $a->is_default])
                ->values()->all(),
            'policies' => DB::table('policies')->where('policyholder_party_id', $model->id)->orderByDesc('inception')->limit(50)
                ->get(['id', 'number', 'status', 'inception', 'expiry'])->map(fn (object $p): array => (array) $p)->values()->all(),
        ]);
    }

    public function storeBankAccount(Request $request, string $party): RedirectResponse
    {
        /** @var array{bank_name: string, account_number: string, is_default?: bool} $data */
        $data = $request->validate(['bank_name' => ['required', 'string', 'max:255'], 'account_number' => ['required', 'string', 'max:64'], 'is_default' => ['sometimes', 'boolean']]);
        $this->parties->addBankAccount($party, $data['bank_name'], $data['account_number'], (bool) ($data['is_default'] ?? false), PageSupport::actor($request));

        return redirect("/parties/{$party}")->with('status', 'Bank account added.');
    }

    public function agents(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $agents = DB::table('producers')->where('type', 'agent')->orderBy('code')->get(['id', 'code', 'party_id', 'branch_id', 'parent_agent_id', 'commission_plan_id', 'status']);
        $names = DB::table('parties')->whereIn('id', $agents->pluck('party_id'))->pluck('display_name', 'id');

        return Inertia::render('agents/Index', [
            'agents' => $agents->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) ($names[$a->party_id] ?? ''), 'party_id' => (string) $a->party_id,
                'branch_id' => (string) $a->branch_id, 'parent_agent_id' => $a->parent_agent_id, 'commission_plan_id' => $a->commission_plan_id, 'status' => (string) $a->status])->values()->all(),
            'parties' => DB::table('parties')->orderBy('display_name')->get(['id', 'display_name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'commissionPlans' => DB::table('commission_plans')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $p): array => (array) $p)->values()->all(),
        ]);
    }

    public function storeAgent(Request $request): RedirectResponse
    {
        /** @var array{party_id: string, code: string, branch_id: string, parent_agent_id?: string|null, commission_plan_id?: string|null} $data */
        $data = $request->validate(['party_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:32'], 'branch_id' => ['required', 'uuid'],
            'parent_agent_id' => ['nullable', 'uuid'], 'commission_plan_id' => ['nullable', 'uuid']]);
        $agent = $this->agents->create($data['party_id'], $data['code'], $data['branch_id'], $data['parent_agent_id'] ?? null, $data['commission_plan_id'] ?? null, PageSupport::actor($request));

        return redirect('/agents')->with('status', "Agent {$agent->code} created.");
    }

    /** @return array{id: string, kind: string, display_name: string, tax_id: string|null, roles: array<int, string>} */
    private static function summary(Party $party): array
    {
        return ['id' => $party->id, 'kind' => $party->kind->value, 'display_name' => $party->display_name, 'tax_id' => $party->tax_id,
            'roles' => array_values($party->roles->sortBy('id')->pluck('role')->map(fn (mixed $r): string => $r instanceof \BackedEnum ? (string) $r->value : (string) $r)->all())];
    }
}
