<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Reports\Application\CommissionStatementReport;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\ReceivableAgeingQuery;
use App\Modules\Insurance\Reports\Application\SuspenseAgeingReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §8 / design §9 Phase 1A reports (read-only): premium register, receivable ageing, suspense ageing, commission statement, P&L and
 * balance sheet — every figure drills to the journals behind it (§2.3 source links, CONTEXT.md "reports drill to journal").
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    // Policy A: issued July, 3 installments, one paid, endorsed in August. Policy B: issued September, cancelled in November. Suspense in September.
    $this->business = function (): array {
        $lifecycle = app(PolicyLifecycle::class);
        $issue = function (string $inception, int $installments) use ($lifecycle): string {
            $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
                $this->world['agent_id'], CarbonImmutable::parse($inception), 12_000_000, 'BDT', $installments), $this->world['admin']);
            $lifecycle->issue($policy->id, CarbonImmutable::parse($inception), $this->world['admin']);

            return $policy->id;
        };
        $a = $issue('2026-07-01', 3);
        $b = $issue('2026-09-01', 1);
        $firstOfA = (string) DB::table('installments')->where('policy_id', $a)->where('no', 1)->value('id');
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 4_000_000, 'BDT',
            CarbonImmutable::parse('2026-07-05'), null, 'r1', [new AllocationLine($firstOfA, 4_000_000)]), $this->world['admin']);
        $lifecycle->endorse($a, CarbonImmutable::parse('2026-08-15'), 1_150_000, 'extra driver', $this->world['admin']);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 80_000, 'BDT',
            CarbonImmutable::parse('2026-09-12'), null, 'unknown', []), $this->world['admin']);
        foreach (DB::table('fiscal_periods')->where('ends', '<=', '2026-10-31')->orderBy('starts')->pluck('id') as $periodId) {
            app(PremiumEarningRun::class)->run((string) $periodId);
        }
        $lifecycle->cancel($b, CarbonImmutable::parse('2026-11-01'), 'sold', $this->world['admin']);

        return [$a, $b];
    };
    $this->journalOf = fn (string $sourceType, string $sourceId): string => (string) DB::table('journals')->where('source_type', $sourceType)->where('source_id', $sourceId)->value('id');
});

/**
 * @param list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}> $rows
 * @return array{account_id: string, code: string, name: string, amount_minor: int, url: string}
 */
function reportRow(array $rows, string $code): array
{
    foreach ($rows as $row) {
        if ($row['code'] === $code) {
            return $row;
        }
    }

    throw new PHPUnit\Framework\AssertionFailedError("No report row for account {$code}.");
}

it('lists written premium per policy transaction with cancellations negative, each drilling to its journal', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$a, $b] = ($this->business)();
        $report = app(PremiumRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-11-30'));
        $cancellation = DB::table('policy_transactions')->where('policy_id', $b)->where('type', 'cancellation')->first();
        $amounts = json_decode((string) $cancellation?->amounts, true);

        expect(array_map(fn (array $r): array => [$r['type'], $r['policy_id'], $r['accounting_date']], $report['rows']))->toBe([
            ['new', $a, '2026-07-01'], ['endorsement', $a, '2026-08-15'], ['new', $b, '2026-09-01'], ['cancellation', $b, '2026-11-01'],
        ])
            ->and($report['rows'][3]['net_minor'])->toBe(-$amounts['unearned_remaining'])
            ->and($report['rows'][3]['tax_minor'])->toBe(-$amounts['tax_reversal'])
            ->and($report['rows'][3]['gross_minor'])->toBe(-$amounts['unearned_remaining'] - $amounts['tax_reversal'])
            ->and($report['rows'][0]['product_code'])->toBe('MOTOR')
            ->and($report['totals']['gross_minor'])->toBe(array_sum(array_column($report['rows'], 'gross_minor')))
            ->and(array_column($report['rows'][2]['journals'], 'journal_id'))->toBe([($this->journalOf)('policy_transaction', (string) DB::table('policy_transactions')->where('policy_id', $b)->where('type', 'new')->value('id'))])
            ->and($report['rows'][2]['journals'][0]['url'])->toBe('/accounting/journals/'.$report['rows'][2]['journals'][0]['journal_id']);

        $september = app(PremiumRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        expect(count($september['rows']))->toBe(1);
    });
});

it('ages receivable installments by days past due, drilling to the journals that billed the policy', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$a, $b] = ($this->business)();
        $report = app(ReceivableAgeingQuery::class)->ageing($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-15'));
        // ASSUMPTION A-9: outstanding amounts are today's (as_of sets the ageing); B's cancellation already credited most of its installment.
        $bRemaining = (int) DB::table('installments')->where('policy_id', $b)->selectRaw('sum(amount_minor - paid_minor - cancelled_minor) as o')->value('o');

        // A: #2 due 2026-08-01 (45 days) 4,000,000; endorsement #4 due 2026-08-15 (31 days) 1,150,000; #3 due 2026-09-01 (14 days) 4,000,000. B #1 due 2026-09-01.
        expect($bRemaining)->toBeGreaterThan(0)
            ->and($report['buckets'])->toBe(['not_due' => 0, '1-30' => 4_000_000 + $bRemaining, '31-60' => 5_150_000, '61-90' => 0, '90+' => 0])
            ->and($report['total_minor'])->toBe(9_150_000 + $bRemaining)
            ->and(array_map(fn (array $r): array => [$r['policy_id'], $r['installment_no'], $r['days_past_due']], $report['rows']))->toBe([[$a, 2, 45], [$a, 4, 31], [$a, 3, 14], [$b, 1, 14]])
            ->and(count($report['rows'][0]['journals']))->toBe(2);

        // Not yet due on 10 August: A #3, A #4 and B #1; A #2 is 9 days past due.
        $earlier = app(ReceivableAgeingQuery::class)->ageing($this->ctx['entity_id'], CarbonImmutable::parse('2026-08-10'));
        expect($earlier['buckets']['not_due'])->toBe(4_000_000 + 1_150_000 + $bRemaining)
            ->and($earlier['buckets']['1-30'])->toBe(4_000_000)
            ->and($earlier['total_minor'])->toBe($report['total_minor']);
    });
});

it('drills suspense ageing and the commission statement to their journals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->business)();
        $suspense = app(SuspenseAgeingReport::class)->ageing($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'));
        $statement = app(CommissionStatementReport::class)->statement($this->world['agent_id'], CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));
        $entryId = (string) DB::table('commission_entries')->value('id');

        expect($suspense['total_minor'])->toBe(80_000)
            ->and($suspense['items'][0]['journals'][0]['journal_id'])->toBe(($this->journalOf)('receipt', $suspense['items'][0]['receipt_id']))
            ->and($statement['totals']['earned_minor'])->toBe(347_826) // gap audit GA-42: 10% of the 3,478,261 net premium in the 4,000,000 allocated (the 15% VAT excluded), not of the cash
            ->and($statement['entries'][0]['journals'][0]['journal_id'])->toBe(($this->journalOf)('commission_entry', $entryId));
    });
});

it('produces the profit and loss for a date range, drilling from accounts to journals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->business)();
        $query = app(FinancialStatementsQuery::class);
        $pnl = $query->profitAndLoss($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        $premiumIncome = reportRow($pnl['income'], '4100');
        $earnedSeptember = (int) DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')->where('p.starts', '2026-09-01')->sum('l.earned_minor');

        expect($premiumIncome['amount_minor'])->toBe($earnedSeptember)
            ->and($pnl['total_income_minor'])->toBe(array_sum(array_column($pnl['income'], 'amount_minor')))
            ->and($pnl['net_profit_minor'])->toBe($pnl['total_income_minor'] - $pnl['total_expense_minor'])
            ->and($premiumIncome['url'])->toBe("/api/reports/accounts/{$premiumIncome['account_id']}/activity?entity_id={$this->ctx['entity_id']}&from=2026-09-01&to=2026-09-30");

        $activity = $query->accountActivity($this->ctx['entity_id'], $premiumIncome['account_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        expect(array_sum(array_map(fn (array $l): int => $l['credit_minor'] - $l['debit_minor'], $activity['lines'])))->toBe($earnedSeptember)
            ->and($activity['closing_minor'] - $activity['opening_minor'])->toBe($earnedSeptember)
            ->and($activity['lines'][0]['url'])->toBe('/accounting/journals/'.$activity['lines'][0]['journal_id']);
    });
});

it('produces a balancing balance sheet as of a date, with current earnings', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->business)();
        $bs = app(FinancialStatementsQuery::class)->balanceSheet($this->ctx['entity_id'], CarbonImmutable::parse('2026-11-30'));
        $receivable = reportRow($bs['assets'], '1100');
        $receivableGl = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('l.account_id', $this->ctx['accounts']['premium_receivable'])
            ->whereIn('j.status', ['posted', 'reversed'])->selectRaw("sum(case when l.side = 'debit' then l.amount_minor else -l.amount_minor end) as b")->value('b');

        expect($bs['total_assets_minor'])->toBe($bs['total_liabilities_minor'] + $bs['total_equity_minor'] + $bs['current_earnings_minor'])
            ->and($receivable['amount_minor'])->toBe($receivableGl)
            ->and($bs['current_earnings_minor'])->toBeGreaterThan(0)
            ->and(str_starts_with($receivable['url'], "/api/reports/accounts/{$receivable['account_id']}/activity"))->toBeTrue();
    });
});

it('serves every report over the API to reports.financial only', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $reader = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));
    $clerk = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create'])));
    $e = $this->ctx['entity_id'];
    $account = $this->ctx['accounts']['premium_income'];

    foreach ([
        "/api/reports/premium-register?entity_id={$e}&from=2026-07-01&to=2026-09-30",
        "/api/reports/receivable-ageing?entity_id={$e}&as_of=2026-09-30",
        "/api/reports/suspense-ageing?entity_id={$e}&as_of=2026-09-30",
        "/api/reports/commission-statement?agent_id={$this->world['agent_id']}&from=2026-07-01&to=2026-09-30",
        "/api/reports/profit-and-loss?entity_id={$e}&from=2026-07-01&to=2026-09-30",
        "/api/reports/balance-sheet?entity_id={$e}&as_of=2026-09-30",
        "/api/reports/accounts/{$account}/activity?entity_id={$e}&from=2026-07-01&to=2026-09-30",
    ] as $url) {
        Pest\Laravel\actingAs($clerk)->getJson($url, $headers)->assertForbidden();
        Pest\Laravel\actingAs($reader)->getJson($url, $headers)->assertOk()->assertJsonStructure(['data']);
    }
});
