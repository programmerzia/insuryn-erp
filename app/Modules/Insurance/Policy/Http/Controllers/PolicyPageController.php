<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Policy\Application\PayerShare;
use App\Modules\Insurance\Policy\Application\PayerStatementQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Policies screens (design §5.4): list and filter, quote (with payers), detail with transactions, installments and the lifecycle actions. */
final class PolicyPageController
{
    public const AREA = ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', 'reports.financial'];

    public function __construct(
        private readonly PolicyLifecycle $lifecycle,
        private readonly PayerStatementQuery $payers,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));
        $entity = PageSupport::entity();
        $page = DB::table('policies as p')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.entity_id', $entity['id'])->when($status !== '', fn ($q) => $q->where('p.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('p.number ilike ?', ["%{$search}%"])->orWhereRaw('h.display_name ilike ?', ["%{$search}%"])))
            ->orderByDesc('p.created_at')->select(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'p.gross_premium_minor', 'p.currency', 'h.display_name as policyholder', 'pr.code as product_code'])
            ->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();

        return Inertia::render('policies/Index', [
            'filters' => ['status' => $status, 'search' => $search],
            'statuses' => array_column(PolicyStatus::cases(), 'value'),
            'policies' => PageSupport::page($page, self::rows($page->items())),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);

        return Inertia::render('policies/Create', [
            'entity' => PageSupport::entity(),
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'products' => DB::table('products')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'parties' => DB::table('parties')->orderBy('display_name')->get(['id', 'display_name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'agents' => DB::table('producers as a')->join('parties as p', 'p.id', '=', 'a.party_id')->where('a.status', 'active')->orderBy('a.code')
                ->get(['a.id', 'a.code', 'p.display_name'])->map(fn (object $a): array => (array) $a)->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{branch_id: string, product_id: string, policyholder_party_id: string, agent_id?: string|null, inception: string, premium: string, installment_count: int, payers?: list<array{party_id: string, share_percent: string}>} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'policyholder_party_id' => ['required', 'uuid'],
            'agent_id' => ['nullable', 'uuid'], 'inception' => ['required', 'date_format:Y-m-d'], 'premium' => ['required', 'string'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:12'], 'payers' => ['sometimes', 'array', 'max:10'],
            'payers.*.party_id' => ['required', 'uuid'], 'payers.*.share_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100']]);
        $entity = PageSupport::entity();
        $payers = array_map(fn (array $p): PayerShare => new PayerShare($p['party_id'], self::basisPoints($p['share_percent'])), $data['payers'] ?? []);
        $policy = $this->lifecycle->quote(new QuoteRequest($entity['id'], $data['branch_id'], $data['product_id'], $data['policyholder_party_id'], $data['agent_id'] ?? null,
            CarbonImmutable::parse($data['inception']), PageSupport::minor('premium', $data['premium'], $entity['currency']), $entity['currency'], (int) $data['installment_count'], $payers),
            PageSupport::actor($request));

        return redirect("/policies/{$policy->id}")->with('status', 'Quote created.');
    }

    public function show(Request $request, string $policy): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = Policy::query()->findOrFail($policy);
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $names = DB::table('parties')->pluck('display_name', 'id');
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, \App\Modules\Platform\Authorization\AuthorizationScope::branch($model->entity_id, $model->branch_id));
        $status = $model->status;

        return Inertia::render('policies/Show', [
            'policy' => ['id' => $model->id, 'number' => $model->number, 'status' => $status->value, 'version' => $model->version, 'inception' => $model->inception->toDateString(),
                'expiry' => $model->expiry->toDateString(), 'channel' => $model->channel, 'currency' => $model->currency, 'policyholder' => (string) ($names[$model->policyholder_party_id] ?? ''),
                'product_code' => (string) DB::table('products')->where('id', $model->product_id)->value('code'), 'agent_code' => $model->agent_id === null ? null : (string) DB::table('producers')->where('id', $model->agent_id)->value('code'),
                'gross_premium' => $money($model->gross_premium_minor), 'net_premium' => $money($model->net_premium_minor), 'tax' => $money($model->tax_minor),
                'cancel_date' => $model->cancel_date?->toDateString()],
            'transactions' => $model->transactions()->get()->map(fn (PolicyTransaction $t): array => ['id' => $t->id, 'type' => $t->type->value, 'effective_date' => $t->effective_date->toDateString(),
                'premium_delta' => $money($t->premium_delta_minor), 'reason' => $t->reason])->values()->all(),
            'installments' => Installment::query()->where('policy_id', $model->id)->orderBy('no')->orderBy('id')->get()->map(fn (Installment $i): array => ['id' => $i->id, 'no' => $i->no,
                'payer' => (string) ($names[$i->payer_party_id] ?? ''), 'due_date' => $i->due_date->toDateString(), 'amount' => $money($i->amount_minor), 'paid' => $money($i->paid_minor),
                'credited' => $money($i->cancelled_minor), 'outstanding' => $money($i->outstanding()), 'status' => $i->status->value])->values()->all(),
            'payers' => array_map(fn (array $p): array => ['name' => $p['name'], 'share_percent' => sprintf('%d.%02d', intdiv($p['share_bp'], 100), $p['share_bp'] % 100), 'billed' => $money($p['billed_minor']),
                'paid' => $money($p['paid_minor']), 'outstanding' => $money($p['outstanding_minor'])], $this->payers->forPolicy($model->id)['payers']),
            'actions' => [
                'issue' => $status === PolicyStatus::Quote && $can('policy.issue'),
                'endorse' => in_array($status, [PolicyStatus::Issued, PolicyStatus::Active], true) && $can('policy.endorse'),
                'cancel' => in_array($status, [PolicyStatus::Issued, PolicyStatus::Active], true) && $can('policy.cancel'),
                'lapse' => $status === PolicyStatus::Active && $can('policy.cancel'),
                'reinstate' => $status === PolicyStatus::Lapsed && $can('policy.issue'),
                'renew' => in_array($status, [PolicyStatus::Active, PolicyStatus::Expired], true) && $can('policy.create'),
            ],
        ]);
    }

    public function issue(Request $request, string $policy): RedirectResponse
    {
        /** @var array{on: string} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d']]);
        $this->lifecycle->issue($policy, CarbonImmutable::parse($data['on']), PageSupport::actor($request));

        return redirect("/policies/{$policy}")->with('status', 'Policy issued.');
    }

    public function endorse(Request $request, string $policy): RedirectResponse
    {
        /** @var array{effective_date: string, premium_delta: string, reason: string} $data */
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'premium_delta' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $currency = (string) Policy::query()->whereKey($policy)->value('currency');
        $this->lifecycle->endorse($policy, CarbonImmutable::parse($data['effective_date']), PageSupport::minor('premium_delta', $data['premium_delta'], $currency, true), $data['reason'], PageSupport::actor($request));

        return redirect("/policies/{$policy}")->with('status', 'Endorsement recorded.');
    }

    public function cancel(Request $request, string $policy): RedirectResponse
    {
        /** @var array{cancel_date: string, reason: string} $data */
        $data = $request->validate(['cancel_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        $this->lifecycle->cancel($policy, CarbonImmutable::parse($data['cancel_date']), $data['reason'], PageSupport::actor($request));

        return redirect("/policies/{$policy}")->with('status', 'Policy cancelled.');
    }

    public function transition(Request $request, string $policy, string $action): RedirectResponse
    {
        /** @var array{reason?: string} $data */
        $data = $request->validate(['reason' => [Rule::requiredIf($action !== 'renew'), 'nullable', 'string', 'max:1000']]);
        $actor = PageSupport::actor($request);
        $result = match ($action) {
            'lapse' => $this->lifecycle->lapse($policy, (string) ($data['reason'] ?? ''), $actor),
            'reinstate' => $this->lifecycle->reinstate($policy, (string) ($data['reason'] ?? ''), $actor),
            'renew' => $this->lifecycle->renew($policy, $actor),
            default => abort(404),
        };

        return redirect("/policies/{$result->id}")->with('status', $action === 'renew' ? 'Renewal quote created.' : 'Policy '.($action === 'lapse' ? 'lapsed.' : 'reinstated.'));
    }

    /**
     * @param array<int, mixed> $items
     * @return list<array<string, mixed>>
     */
    private static function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $p) {
            /** @var object{id: string, number: string|null, status: string, inception: string, expiry: string, gross_premium_minor: int|string, currency: string, policyholder: string|null, product_code: string|null} $p */
            $rows[] = ['id' => (string) $p->id, 'number' => $p->number, 'status' => (string) $p->status, 'inception' => (string) $p->inception, 'expiry' => (string) $p->expiry,
                'policyholder' => (string) $p->policyholder, 'product_code' => (string) $p->product_code, 'gross_premium' => PageSupport::money((int) $p->gross_premium_minor, (string) $p->currency)];
        }

        return $rows;
    }

    /** "60" or "33.33" percent → basis points, with string arithmetic. */
    private static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($percent), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
