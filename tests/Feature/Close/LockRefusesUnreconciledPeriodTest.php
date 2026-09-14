<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/**
 * Review finding (critical): design §5.7 INVARIANT "period.lock() refuses if … any reconciliation variance" held only for *stored* runs. A
 * control-account posting made after the reconciliation tasks passed (e.g. under accounting.post_in_soft_locked between tasks 13 and 16) was
 * never re-checked, and the period locked with the subledger and GL apart. The lock must judge the ledger as it stands.
 */
beforeEach(function (): void {
    // Slice 2.1b (D-56): a month is locked only once it has ended on the business clock; these cases close September, so they run on 1 October.
    travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $this->checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control', 'accounting.post_in_soft_locked']);
    $this->september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    // A 10,000 adjustment on the premium receivable control account with no subledger counterpart; $side reverses it.
    $this->adjustReceivable = function (Side $side, string $date): void {
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse($date), 'Receivable adjustment', JournalKind::Adjustment, 'review', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['premium_receivable'], $side, 10_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['rounding_difference'], $side->opposite(), 10_000, ['branch' => $this->ctx['branch_id']]),
        ]), $this->maker);
        $journals->submit($journal->id, $this->maker);
        $journals->approve($journal->id, $this->checker);
    };
});

it('refuses the close lock task when a variance is posted after the reconciliation tasks passed, and records it', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $close = app(PeriodCloseService::class);
        $runId = $close->start($this->september, $this->world['admin']);
        $task = fn (string $code): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', $code)->value('id');
        foreach (['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'claims_reconciliation', 'commission_reconciliation'] as $code) {
            $close->execute($task($code), $this->world['admin']);
        }
        $close->execute($task('accruals'), $this->world['admin'], 'none');
        $close->execute($task('trial_balance'), $this->world['admin']);            // soft-locks September
        ($this->adjustReceivable)(Side::Debit, '2026-09-25');                         // posted into the soft-locked period by a privileged user
        $close->execute($task('financial_statements'), $this->world['admin']);
        $close->execute($task('sign_off'), $this->world['admin']);

        expect(thrownBy(fn () => $close->execute($task('period_lock'), $this->world['admin']), PeriodTransitionException::class)->reasonCode)->toBe('RECONCILIATION_VARIANCE')
            ->and(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('soft_locked')
            ->and(DB::table('period_close_tasks')->where('id', $task('period_lock'))->value('status'))->toBe('pending')
            ->and(DB::table('period_close_runs')->where('id', $runId)->value('status'))->toBe('running')
            ->and((int) DB::table('reconciliation_runs')->where('period_id', $this->september)->where('subledger', 'premium')->where('status', 'variance')->value('variance_minor'))->toBe(-10_000)
            ->and(DB::table('reconciliation_exceptions')->where('object_type', 'journal')->count())->toBe(1);

        ($this->adjustReceivable)(Side::Credit, '2026-09-26');
        $close->execute($task('period_lock'), $this->world['admin']);

        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('locked')
            ->and(DB::table('period_close_runs')->where('id', $runId)->value('status'))->toBe('completed')
            ->and(DB::table('reconciliation_runs')->where('period_id', $this->september)->where('status', 'variance')->count())->toBe(0);
    });
});

it('refuses a direct period lock while the ledger differs from a subledger, even when every stored run is clean', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $periods = app(FiscalPeriodService::class);
        app(ReconciliationService::class)->runAll($this->september);
        ($this->adjustReceivable)(Side::Debit, '2026-09-10');
        $periods->softLock($this->september, $this->world['admin']);

        $refusal = thrownBy(fn () => $periods->lock($this->september, $this->world['admin']), PeriodTransitionException::class);
        expect($refusal->reasonCode)->toBe('RECONCILIATION_VARIANCE')
            ->and($refusal->getMessage())->toContain('premium')
            ->and(DB::table('reconciliation_runs')->where('period_id', $this->september)->where('status', 'variance')->count())->toBe(0)
            ->and(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('soft_locked');

        ($this->adjustReceivable)(Side::Credit, '2026-09-11');
        $periods->lock($this->september, $this->world['admin']);
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('locked');
    });
});
