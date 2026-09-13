<?php

declare(strict_types=1);

namespace App\Http\Distribution;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Distribution\Application\Advances\AdvanceService;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Hierarchy\HierarchyNode;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Policy\Application\PersistencyQuery;
use App\Modules\Insurance\Policy\Application\ProductionQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Distribution design note §6 producers queue and producer page (slice D8), composed at the app layer: producers, licences, hierarchy and
 * advances from Distribution; commission, statements and production from Insurance.
 */
final class ProducersPageController
{
    public const AREA = ['agent.manage', 'commission.approve', 'commission.pay', 'commission.manage_plans', 'reports.financial', 'reports.regulatory'];
    private const EXPIRY_WARNING_DAYS = 60;

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly HierarchyQuery $hierarchy,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $today = CarbonImmutable::today();
        $names = DB::table('parties')->pluck('display_name', 'id');
        $codes = DB::table('producers')->pluck('code', 'id');
        $channels = DB::table('channels')->orderBy('code')->get(['id', 'code', 'name', 'type']);
        $branches = DB::table('branches')->orderBy('code')->get(['id', 'code', 'name']);
        $licences = DB::table('producer_licences')->where('status', 'active')->groupBy('producer_id')->selectRaw('producer_id, max(expires_on) as expires_on')->pluck('expires_on', 'producer_id');
        $advances = DB::table('producer_advances')->where('status', 'open')->groupBy('producer_id')->selectRaw('producer_id, sum(balance_minor) as balance')->pluck('balance', 'producer_id');
        $pending = DB::table('commission_statements')->whereIn('status', ['draft', 'approved'])->groupBy('agent_id')->selectRaw('agent_id, count(*) as n')->pluck('n', 'agent_id');
        $currency = PageSupport::entity()['currency'];

        $rows = [];
        foreach ($this->producers->all() as $producer) {
            $position = $this->hierarchy->positionAt($producer->id, $today);
            $expires = $licences[$producer->id] ?? null;
            $licenceState = match (true) {
                $expires === null => 'none',
                (string) $expires < $today->toDateString() => 'expired',
                (string) $expires <= $today->addDays(self::EXPIRY_WARNING_DAYS)->toDateString() => 'expiring',
                default => 'valid',
            };
            $balance = (int) ($advances[$producer->id] ?? 0);
            $statements = (int) ($pending[$producer->id] ?? 0);
            $rows[] = ['id' => $producer->id, 'code' => $producer->code, 'name' => (string) ($names[$producer->partyId] ?? ''), 'type' => $producer->type, 'status' => $producer->status,
                'channel' => (string) ($channels->firstWhere('id', $producer->channelId)->code ?? ''), 'branch' => (string) ($branches->firstWhere('id', $producer->branchId)->code ?? ''),
                'level' => $position?->level_code, 'parent_code' => $position?->parent_producer_id === null ? null : (string) ($codes[$position->parent_producer_id] ?? ''),
                'licence_expires_on' => $expires === null ? null : (string) $expires, 'licence_state' => $licenceState,
                'advance_balance' => $balance === 0 ? null : PageSupport::money($balance, $currency), 'pending_statements' => $statements,
                'attention' => array_values(array_filter([
                    $licenceState === 'expiring' ? 'Licence expiring' : null, $licenceState === 'expired' || ($licenceState === 'none' && $producer->status === 'active') ? 'No valid licence' : null,
                    $balance > 0 ? 'Advance outstanding' : null, $statements > 0 ? 'Statement pending' : null,
                ]))];
        }

        return Inertia::render('distribution/producers/Index', [
            'producers' => $rows,
            'channels' => $channels->map(fn (object $c): array => (array) $c)->values()->all(),
            'branches' => $branches->map(fn (object $b): array => (array) $b)->values()->all(),
            'parties' => DB::table('parties')->whereNotIn('id', DB::table('producers')->select('party_id'))->orderBy('display_name')->limit(500)->get(['id', 'display_name'])
                ->map(fn (object $p): array => (array) $p)->values()->all(),
            'can' => ['manage' => $this->permissions->has($actor, 'agent.manage'), 'export_register' => $this->permissions->has($actor, 'reports.regulatory')],
        ]);
    }

    public function store(Request $request, ProducerService $service): RedirectResponse
    {
        /** @var array{party_id: string, code: string, type: string, branch_id: string, channel_id?: string|null, joined_on?: string|null, employee_id?: string|null} $data */
        $data = $request->validate(['party_id' => ['required', 'uuid', Rule::exists('parties', 'id'), Rule::unique('producers', 'party_id')],
            'code' => ['required', 'string', 'max:32', Rule::unique('producers', 'code')], 'type' => ['required', Rule::in(['agent', 'agency_org', 'bdo', 'broker', 'partner'])],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')], 'channel_id' => ['nullable', 'uuid', Rule::exists('channels', 'id')],
            'joined_on' => ['nullable', 'date_format:Y-m-d'], 'employee_id' => ['nullable', 'uuid']],
            ['party_id.unique' => 'This party is already a producer.', 'code.unique' => 'Another producer has this code.']);
        $producer = $service->create(new CreateProducer($data['party_id'], $data['code'], $data['type'], $data['branch_id'], ($data['channel_id'] ?? null) ?: null,
            employeeId: ($data['employee_id'] ?? null) ?: null, joinedOn: ($data['joined_on'] ?? null) ? CarbonImmutable::parse($data['joined_on']) : null), PageSupport::actor($request));

        return redirect("/distribution/producers/{$producer->id}")->with('status', "Producer {$producer->code} created.");
    }

    public function show(Request $request, string $producer, ObjectHistory $history, ProductionQuery $production, PersistencyQuery $persistency, TargetService $targets): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = $this->producers->find($producer) ?? abort(404);
        $on = CarbonImmutable::parse((string) $request->query('on', 'today'));
        $currency = PageSupport::entity()['currency'];
        $money = fn (int $minor): string => PageSupport::money($minor, $currency);
        $position = $this->hierarchy->positionAt($model->id, $on);
        $codes = DB::table('producers')->pluck('code', 'id');
        $advanceBalance = (int) DB::table('producer_advances')->where('producer_id', $model->id)->where('status', 'open')->sum('balance_minor');
        $licence = DB::table('producer_licences')->where('producer_id', $model->id)->where('status', 'active')->orderByDesc('expires_on')->first(['licence_no', 'class', 'expires_on']);

        $periods = ['month' => [$on->startOfMonth(), $on->endOfMonth(), 'monthly'], 'quarter' => [$on->firstOfQuarter(), $on->lastOfQuarter(), 'quarterly'], 'year' => [$on->startOfYear(), $on->endOfYear(), 'annual']];
        $productionRows = [];
        foreach ($periods as $key => [$from, $to, $periodType]) {
            $values = fn (string $metric): int => $production->metric($metric, $from, $to, [$model->id])[$model->id] ?? 0;
            $target = fn (string $metric): ?int => $targets->valueFor('producer', $model->id, $periodType, $from->startOfDay(), $metric);
            $productionRows[$key] = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'premium' => $money($values('premium')), 'policies' => $values('policies'),
                'collections' => $money($values('collections')), 'premium_target' => ($t = $target('premium')) === null ? null : $money($t)];
        }

        return Inertia::render('distribution/producers/Show', [
            'producer' => ['id' => $model->id, 'code' => $model->code, 'name' => (string) DB::table('parties')->where('id', $model->partyId)->value('display_name'), 'type' => $model->type,
                'status' => $model->status, 'party_id' => $model->partyId, 'joined_on' => $model->joinedOn, 'employee_id' => $model->employeeId],
            'on' => $on->toDateString(),
            'facts' => [
                ['label' => 'Type', 'value' => ['agent' => 'Agent', 'agency_org' => 'Agency', 'bdo' => 'BDO', 'broker' => 'Broker', 'partner' => 'Partner'][$model->type] ?? $model->type],
                ['label' => 'Channel', 'value' => (string) DB::table('channels')->where('id', $model->channelId)->value('name')],
                ['label' => 'Branch', 'value' => (string) DB::table('branches')->where('id', $model->branchId)->value('code')],
                ['label' => 'Level', 'value' => $position === null || $position->level_code === null ? '—' : (string) $position->level_code],
                ['label' => 'Licence', 'value' => $licence === null ? 'None valid' : "{$licence->licence_no}, to ".CarbonImmutable::parse((string) $licence->expires_on)->format('j M Y')],
                ['label' => 'Advances outstanding', 'value' => $money($advanceBalance)],
            ],
            'licences' => DB::table('producer_licences')->where('producer_id', $model->id)->orderByDesc('expires_on')->get(['id', 'authority', 'licence_no', 'class', 'issued_on', 'expires_on', 'status', 'status_reason'])
                ->map(fn (object $l): array => (array) $l)->values()->all(),
            'advances' => DB::table('producer_advances')->where('producer_id', $model->id)->orderByDesc('issued_on')->get(['id', 'issued_on', 'amount_minor', 'balance_minor', 'status', 'recovery_rule'])
                ->map(fn (object $a): array => ['id' => (string) $a->id, 'issued_on' => (string) $a->issued_on, 'amount' => $money((int) $a->amount_minor), 'balance' => $money((int) $a->balance_minor),
                    'status' => (string) $a->status, 'recovery' => self::recoveryWords((array) json_decode((string) $a->recovery_rule, true))])->values()->all(),
            'hierarchy' => [
                'chain' => array_map(fn (HierarchyNode $n): string => $n->code, $this->hierarchy->hierarchyAt($model->id, $on)),
                'history' => array_map(fn (\stdClass $h): array => ['from' => (string) $h->effective_from, 'to' => $h->effective_to === null ? null : (string) $h->effective_to,
                    'parent_code' => $h->parent_producer_id === null ? null : (string) ($codes[$h->parent_producer_id] ?? ''), 'level' => $h->level_code], $this->hierarchy->historyOf($model->id)),
                'team' => DB::table('producer_hierarchy as h')->join('producers as p', 'p.id', '=', 'h.producer_id')->where('h.parent_producer_id', $model->id)
                    ->where('h.effective_from', '<=', $on->toDateString())->where(fn ($q) => $q->whereNull('h.effective_to')->orWhere('h.effective_to', '>', $on->toDateString()))
                    ->orderBy('p.code')->get(['p.id', 'p.code', 'h.level_code'])->map(fn (object $r): array => (array) $r)->values()->all(),
            ],
            'compensation' => [
                'entries' => DB::table('commission_entries as e')->leftJoin('policies as p', 'p.id', '=', 'e.policy_id')->where('e.agent_id', $model->id)->orderByDesc('e.earned_on')->orderByDesc('e.created_at')->limit(100)
                    ->get(['e.id', 'e.earned_on', 'e.kind', 'e.beneficiary_role', 'e.level_code', 'p.number', 'e.policy_id', 'e.rate_bp', 'e.amount_minor', 'e.withholding_minor', 'e.status'])
                    ->map(fn (object $e): array => ['id' => (string) $e->id, 'earned_on' => (string) $e->earned_on, 'kind' => (string) $e->kind, 'role' => (string) $e->beneficiary_role, 'level' => $e->level_code,
                        'policy_number' => $e->number, 'policy_id' => $e->policy_id, 'rate_percent' => $e->rate_bp === null ? null : PageSupport::percent((int) $e->rate_bp),
                        'amount' => $money((int) $e->amount_minor), 'withholding' => $money((int) $e->withholding_minor), 'status' => (string) $e->status])->values()->all(),
                'exceptions' => DB::table('compliance_exceptions')->where('producer_id', $model->id)->orderByDesc('occurred_on')->limit(50)->get(['occurred_on', 'reason_code', 'message'])
                    ->map(fn (object $x): array => (array) $x)->values()->all(),
            ],
            'production' => [...$productionRows, 'persistency_13' => ($p = $persistency->monthBp($model->id, $on)) === null ? null : PageSupport::percent($p),
                'persistency_25' => ($q = $persistency->monthBp($model->id, $on, 25)) === null ? null : PageSupport::percent($q)],
            'statements' => DB::table('commission_statements')->where('agent_id', $model->id)->orderByDesc('created_at')->limit(50)
                ->get(['id', 'number', 'period_end', 'up_to', 'net_minor', 'status', 'paid_via', 'paid_on'])
                ->map(fn (object $s): array => ['id' => (string) $s->id, 'number' => $s->number, 'period' => (string) ($s->period_end ?? $s->up_to), 'net' => $money((int) $s->net_minor),
                    'status' => (string) $s->status, 'paid_via' => (string) $s->paid_via, 'paid_on' => $s->paid_on])->values()->all(),
            'parents' => DB::table('producers')->where('id', '<>', $model->id)->orderBy('code')->get(['id', 'code'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'levels' => DB::table('hierarchy_levels')->groupBy('level_code')->orderByRaw('min(rank)')->pluck('level_code')->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('status', 'active')->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'can' => ['manage' => $this->permissions->has($actor, 'agent.manage'), 'advance' => $this->permissions->has($actor, 'commission.pay')],
            'audit' => Inertia::defer(fn (): array => $history->audit([['producer', $model->id]]), 'history'),
        ]);
    }

    public function storeLicence(Request $request, string $producer, LicenceService $licences): RedirectResponse
    {
        /** @var array{licence_no: string, class: string, issued_on: string, expires_on: string, authority?: string|null} $data */
        $data = $request->validate(['authority' => ['nullable', 'string', 'max:32'], 'licence_no' => ['required', 'string', 'max:64', Rule::unique('producer_licences', 'licence_no')->where('authority', $request->input('authority') ?: 'IDRA')],
            'class' => ['required', Rule::in(LicenceService::CLASSES)], 'issued_on' => ['required', 'date_format:Y-m-d'], 'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on']],
            ['licence_no.unique' => 'This licence number is already recorded.', 'expires_on.after_or_equal' => 'A licence cannot expire before it is issued.']);
        $licences->record(new RecordLicence($producer, $data['licence_no'], $data['class'], CarbonImmutable::parse($data['issued_on']), CarbonImmutable::parse($data['expires_on']),
            ($data['authority'] ?? null) ?: 'IDRA'), PageSupport::actor($request));

        return back()->with('status', "Licence {$data['licence_no']} recorded.");
    }

    public function licenceStatus(Request $request, string $licence, string $action, LicenceService $licences): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        match ($action) {
            'suspend' => $licences->suspend($licence, $data['reason'], PageSupport::actor($request)),
            'revoke' => $licences->revoke($licence, $data['reason'], PageSupport::actor($request)),
            default => $licences->reinstate($licence, $data['reason'], PageSupport::actor($request)),
        };

        return back()->with('status', 'Licence '.['suspend' => 'suspended', 'revoke' => 'revoked', 'reinstate' => 'reinstated'][$action].'.');
    }

    public function issueAdvance(Request $request, string $producer, AdvanceService $advances): RedirectResponse
    {
        /** @var array{amount: string, issued_on: string, recovery: string, recovery_percent?: string|null, bank_account_id?: string|null} $data */
        $data = $request->validate(['amount' => ['required', 'string'], 'issued_on' => ['required', 'date_format:Y-m-d'], 'recovery' => ['required', Rule::in(['full', 'percent_of_net'])],
            'recovery_percent' => ['required_if:recovery,percent_of_net', 'nullable', 'string'], 'bank_account_id' => ['nullable', 'uuid']]);
        $currency = PageSupport::entity()['currency'];
        $amount = PageSupport::minor('amount', $data['amount'], $currency);
        $rule = $data['recovery'] === 'full' ? ['type' => 'full'] : ['type' => 'percent_of_net', 'bp' => PageSupport::basisPoints('recovery_percent', $data['recovery_percent'] ?? '')];
        $advances->issue($producer, $amount, $rule, CarbonImmutable::parse($data['issued_on']), PageSupport::actor($request), ($data['bank_account_id'] ?? null) ?: null);

        return back()->with('status', 'Advance of '.PageSupport::money($amount, $currency).' issued.');
    }

    public function updateStatus(Request $request, string $producer, ProducerService $service): RedirectResponse
    {
        /** @var array{status: string} $data */
        $data = $request->validate(['status' => ['required', Rule::in(['applicant', 'active', 'suspended'])]]);
        $updated = $service->update($producer, ['status' => $data['status']], PageSupport::actor($request));

        return back()->with('status', "{$updated->code} is now {$updated->status}.");
    }

    /** @param array<mixed> $rule */
    private static function recoveryWords(array $rule): string
    {
        return ($rule['type'] ?? null) === 'full' ? 'From each statement in full' : 'From each statement, '.PageSupport::percent((int) ($rule['bp'] ?? 0)).'% of the net';
    }
}
