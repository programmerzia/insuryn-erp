<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Commission\Application\IncentiveRun;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Distribution design note §6 "statement run workbench (preview → approve → pay)" (slice D8) over CommissionStatementRun and CommissionPayoutService. */
final class StatementWorkbenchController
{
    private const AREA = ['commission.approve', 'commission.pay', 'reports.financial'];
    private const ROUTE_WORDS = ['bank' => 'the bank', 'payroll' => 'payroll', 'ap' => 'accounts payable'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $periodEnd = CarbonImmutable::parse((string) $request->query('period_end', CarbonImmutable::today()->subMonthNoOverflow()->endOfMonth()->toDateString()))->endOfMonth();
        $money = fn (mixed $minor): string => PageSupport::money((int) $minor, $entity['currency']);
        $statements = DB::table('commission_statements as s')->join('producers as p', 'p.id', '=', 's.agent_id')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')
            ->where('s.entity_id', $entity['id'])->where('s.period_end', $periodEnd->toDateString())->orderBy('p.code')
            ->get(['s.*', 'p.code', 'pa.display_name']);

        return Inertia::render('distribution/statements/Index', [
            'periodEnd' => $periodEnd->toDateString(),
            'periods' => array_map(fn (int $i): string => CarbonImmutable::today()->startOfMonth()->subMonths($i)->endOfMonth()->toDateString(), range(0, 12)),
            'statements' => $statements->map(fn (object $s): array => ['id' => (string) $s->id, 'number' => $s->number, 'producer_id' => (string) $s->agent_id, 'producer_code' => (string) $s->code,
                'producer_name' => (string) $s->display_name, 'paid_via' => (string) $s->paid_via, 'earned' => $money($s->earned_minor), 'override' => $money($s->override_minor),
                'bonus' => $money($s->bonus_minor), 'clawback' => $money($s->clawback_minor), 'withholding' => $money($s->withholding_minor), 'advances' => $money($s->advances_recovered_minor),
                'net' => $money($s->net_minor), 'status' => (string) $s->status, 'approved_by_me' => $s->approved_by === $actor])->values()->all(),
            'entries' => DB::table('commission_entries as e')->leftJoin('policies as pol', 'pol.id', '=', 'e.policy_id')->whereIn('e.statement_id', $statements->pluck('id'))->orderBy('e.earned_on')
                ->get(['e.statement_id', 'e.earned_on', 'e.kind', 'e.beneficiary_role', 'pol.number', 'e.amount_minor', 'e.withholding_minor'])
                ->map(fn (object $e): array => ['statement_id' => (string) $e->statement_id, 'earned_on' => (string) $e->earned_on, 'kind' => $e->kind === 'earned' ? (string) $e->beneficiary_role : (string) $e->kind,
                    'policy_number' => $e->number, 'amount' => $money($e->amount_minor), 'withholding' => $money($e->withholding_minor)])->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $entity['id'])->where('status', 'active')->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'can' => ['approve' => $this->permissions->has($actor, 'commission.approve'), 'pay' => $this->permissions->has($actor, 'commission.pay')],
        ]);
    }

    public function prepare(Request $request, CommissionStatementRun $run): RedirectResponse
    {
        $periodEnd = $this->periodEnd($request);
        $ids = $run->prepare(PageSupport::entity()['id'], $periodEnd, PageSupport::actor($request));
        $count = count($ids);

        return redirect('/distribution/statements?period_end='.$periodEnd->toDateString())
            ->with('status', $count === 0 ? "Nothing to pay for {$periodEnd->format('j M Y')}." : "{$count} draft ".($count === 1 ? 'statement' : 'statements')." prepared for {$periodEnd->format('j M Y')}.");
    }

    public function runIncentives(Request $request, IncentiveRun $incentives): RedirectResponse
    {
        $periodEnd = $this->periodEnd($request);
        $awards = $incentives->run(PageSupport::entity()['id'], $periodEnd, PageSupport::actor($request));

        return back()->with('status', $awards === 0 ? 'No new incentive awards.' : "{$awards} incentive ".($awards === 1 ? 'award' : 'awards').' added. Prepare statements to include them.');
    }

    public function approve(Request $request, string $statement, CommissionStatementRun $run): RedirectResponse
    {
        /** @var array{on?: string|null} $data */
        $data = $request->validate(['on' => ['nullable', 'date_format:Y-m-d']]);
        $approved = $run->approve($statement, PageSupport::actor($request), CarbonImmutable::parse(($data['on'] ?? null) ?: 'today'));

        return back()->with('status', "Statement {$approved->number} approved. Someone else pays it.");
    }

    public function pay(Request $request, string $statement, CommissionPayoutService $payouts): RedirectResponse
    {
        /** @var array{paid_on?: string|null, bank_account_id?: string|null} $data */
        $data = $request->validate(['paid_on' => ['nullable', 'date_format:Y-m-d'], 'bank_account_id' => ['nullable', 'uuid']]);
        $paid = $payouts->pay($statement, ($data['bank_account_id'] ?? null) ?: null, PageSupport::actor($request), CarbonImmutable::parse(($data['paid_on'] ?? null) ?: 'today'));

        return back()->with('status', "Statement {$paid->number} paid through ".self::ROUTE_WORDS[$paid->paid_via].'.');
    }

    private function periodEnd(Request $request): CarbonImmutable
    {
        /** @var array{period_end: string} $data */
        $data = $request->validate(['period_end' => ['required', 'date_format:Y-m-d']]);

        return CarbonImmutable::parse($data['period_end'])->endOfMonth();
    }
}
