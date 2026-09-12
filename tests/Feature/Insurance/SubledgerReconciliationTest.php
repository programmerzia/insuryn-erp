<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Infrastructure\Jobs\ReconciliationJob;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.1 subledger classification (premium, suspense, commission reconcile to their control accounts), §6.2 contract, §6.3
 * ReconciliationService: runs and exceptions, variance blocks close. Subledger balances are computed as of the reconciliation date.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->periodFor = fn (string $date): string => (string) DB::table('fiscal_periods')->where('starts', '<=', $date)->where('ends', '>=', $date)->value('id');
    $this->runs = fn (string $periodId): array => DB::table('reconciliation_runs')->where('period_id', $periodId)->orderBy('subledger')
        ->get(['subledger', 'subledger_balance_minor', 'gl_balance_minor', 'variance_minor', 'status'])
        ->map(fn (object $r): array => [(string) $r->subledger, (int) $r->subledger_balance_minor, (int) $r->gl_balance_minor, (int) $r->variance_minor, (string) $r->status])->all();
    // A busy quarter: two policies, a partial allocation, suspense allocated later, an endorsement, a cancellation, commission.
    $this->businessQuarter = function (): void {
        $lifecycle = app(PolicyLifecycle::class);
        $issue = function (string $inception) use ($lifecycle): array {
            $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
                $this->world['agent_id'], CarbonImmutable::parse($inception), 12_000_000, 'BDT', 3), $this->world['admin']);
            $lifecycle->issue($policy->id, CarbonImmutable::parse($inception), $this->world['admin']);

            return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
        };
        [$first, $firstInstallments] = $issue('2026-09-01');
        [$second, $secondInstallments] = $issue('2026-09-10');
        $receipts = app(ReceiptService::class);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 5_000_000, 'BDT', CarbonImmutable::parse('2026-09-12'),
            null, 'r1', [new AllocationLine($firstInstallments[0], 4_000_000)]), $this->world['admin']);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 2_000_000, 'BDT', CarbonImmutable::parse('2026-09-25'),
            null, '??', []), $this->world['admin']);
        app(SuspenseService::class)->allocate((string) DB::table('suspense_items')->where('amount_minor', 2_000_000)->value('id'), $secondInstallments[0], 1_500_000,
            $this->world['admin'], CarbonImmutable::parse('2026-10-03'));
        $lifecycle->endorse($second, CarbonImmutable::parse('2026-10-10'), 1_150_000, 'extra driver', $this->world['admin']);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 4_000_000, 'BDT', CarbonImmutable::parse('2026-10-20'),
            null, 'r3', [new AllocationLine($firstInstallments[1], 4_000_000)]), $this->world['admin']);
        $lifecycle->cancel($first, CarbonImmutable::parse('2026-11-15'), 'sold', $this->world['admin']);
    };
});

it('registers the premium, suspense, commission and claims reconcilers', function (): void {
    $subledgers = array_map(fn (SubledgerReconciler $r): string => $r->subledger(), iterator_to_array(app()->tagged(SubledgerReconciler::class), false));
    sort($subledgers);

    expect($subledgers)->toBe(['claims', 'commission', 'premium', 'suspense']);
});

it('reconciles clean at every month end of real business, as of that date', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->businessQuarter)();
        $service = app(ReconciliationService::class);

        foreach (['2026-09-30', '2026-10-31', '2026-11-30'] as $monthEnd) {
            $service->runAll(($this->periodFor)($monthEnd));
        }

        foreach (['2026-09-30', '2026-10-31', '2026-11-30'] as $monthEnd) {
            foreach (($this->runs)(($this->periodFor)($monthEnd)) as [$subledger, $sub, $gl, $variance, $status]) {
                expect([$subledger, $variance, $status])->toBe([$subledger, 0, 'clean'])->and($sub)->toBe($gl);
            }
        }
        [$claims, $commission, $premium, $suspense] = ($this->runs)(($this->periodFor)('2026-09-30'));
        expect($premium[1])->toBe(24_000_000 - 4_000_000)     // two policies issued, one allocation in September
            ->and($suspense[1])->toBe(1_000_000 + 2_000_000)  // r1 remainder + unallocated r2 (allocated in October)
            ->and($commission[1])->toBe(400_000)
            ->and($claims[1])->toBe(0)
            ->and(DB::table('reconciliation_exceptions')->count())->toBe(0);
    });
});

it('reports a variance with drill-down when a manual journal hits a control account, and blocks the period lock', function (): void {
    $maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);
    $closer = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock']);

    asTenant($this->ctx['tenant_id'], function () use ($maker, $checker, $closer): void {
        ($this->businessQuarter)();
        $policyId = (string) DB::table('policies')->where('inception', '2026-09-10')->value('id');
        $september = ($this->periodFor)('2026-09-30');
        $adjust = function (int $amount, Side $receivableSide, array $dims) use ($maker, $checker): string {
            $journals = app(ManualJournalService::class);
            $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-28'), 'Receivable write-up', JournalKind::Adjustment,
                'test variance', 'BDT', [
                    new ManualJournalLine($this->ctx['accounts']['premium_receivable'], $receivableSide, $amount, $dims + ['branch' => $this->ctx['branch_id']]),
                    new ManualJournalLine($this->ctx['accounts']['rounding_difference'], $receivableSide->opposite(), $amount, ['branch' => $this->ctx['branch_id']]),
                ]), $maker);
            $journals->submit($journal->id, $maker);
            $journals->approve($journal->id, $checker);

            return $journal->id;
        };
        $withPolicy = $adjust(70_000, Side::Debit, ['policy' => $policyId]);
        $unattributed = $adjust(30_000, Side::Debit, []);

        app(ReconciliationService::class)->runAll($september);
        $premium = DB::table('reconciliation_runs')->where('period_id', $september)->where('subledger', 'premium')->first();
        $exceptions = DB::table('reconciliation_exceptions')->where('run_id', $premium?->id)->orderBy('object_type')
            ->get(['object_type', 'object_id', 'expected_minor', 'actual_minor'])->map(fn (object $e): array => (array) $e)->all();

        expect([(int) $premium?->variance_minor, $premium?->status])->toBe([-100_000, 'variance'])
            ->and($exceptions)->toEqual([
                ['object_type' => 'journal', 'object_id' => $unattributed, 'expected_minor' => 0, 'actual_minor' => 30_000],
                ['object_type' => 'policy', 'object_id' => $policyId, 'expected_minor' => 12_000_000, 'actual_minor' => 12_070_000],
            ])
            ->and(DB::table('reconciliation_runs')->where('period_id', $september)->where('subledger', '<>', 'premium')->pluck('status')->unique()->values()->all())->toBe(['clean']);

        app(FiscalPeriodService::class)->softLock($september, $closer);
        expect(thrownBy(fn () => app(FiscalPeriodService::class)->lock($september, $closer), PeriodTransitionException::class)->reasonCode)->toBe('RECONCILIATION_VARIANCE');

        // correcting journals (the soft-locked period needs post_in_soft_locked, so correct in the next open period and reconcile its end)
        $october = ($this->periodFor)('2026-10-31');
        $adjustOctober = function (int $amount, array $dims) use ($maker, $checker): void {
            $journals = app(ManualJournalService::class);
            $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-05'), 'Reverse write-up', JournalKind::Adjustment,
                'fix variance', 'BDT', [
                    new ManualJournalLine($this->ctx['accounts']['rounding_difference'], Side::Debit, $amount, ['branch' => $this->ctx['branch_id']]),
                    new ManualJournalLine($this->ctx['accounts']['premium_receivable'], Side::Credit, $amount, $dims + ['branch' => $this->ctx['branch_id']]),
                ]), $maker);
            $journals->submit($journal->id, $maker);
            $journals->approve($journal->id, $checker);
        };
        $adjustOctober(70_000, ['policy' => $policyId]);
        $adjustOctober(30_000, []);
        app(ReconciliationService::class)->runAll($october);

        expect(DB::table('reconciliation_runs')->where('period_id', $october)->where('subledger', 'premium')->value('status'))->toBe('clean');

        // A clean rerun of the variance period resolves its earlier variance run, with a note.
        app(ReconciliationService::class)->runAll($september, CarbonImmutable::parse('2026-10-05'));
        $resolved = DB::table('reconciliation_runs')->where('id', $premium?->id)->first();
        expect($resolved?->status)->toBe('resolved')->and((string) $resolved?->resolution_note)->toContain('clean rerun');
    });
});

it('reconciles every started, unlocked period of every tenant nightly', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => ($this->businessQuarter)());

    CarbonImmutable::setTestNow('2026-10-21 03:00:00');
    app()->call([new ReconciliationJob(), 'handle']);
    CarbonImmutable::setTestNow();

    asTenant($this->ctx['tenant_id'], function (): void {
        expect(DB::table('reconciliation_runs')->distinct()->count('period_id'))->toBe(4) // July, August, September, October (to date)
            ->and(DB::table('reconciliation_runs')->count())->toBe(16) // × 4 subledgers
            ->and(DB::table('reconciliation_runs')->where('status', '<>', 'clean')->count())->toBe(0);
    });
});
