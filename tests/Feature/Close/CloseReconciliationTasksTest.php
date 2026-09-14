<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-43: the close reconciles the unearned premium register, suspense, VAT payable and stamp duty payable to the GL before the trial balance,
 * like premium, claims and commission. A variance blocks the task and the lock. VAT and stamp duty payments to the government, posted by manual journal
 * without a policy, are outside the register (A-204); on the unearned premium reserve every line counts (A-61).
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $this->checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);
    $this->september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    $this->journal = function (string $debitRole, string $creditRole, int $amount, array $dimensions = []): void {
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-20'), 'Adjustment', JournalKind::Adjustment, 'test', 'BDT', [
            new ManualJournalLine($this->ctx['accounts'][$debitRole], Side::Debit, $amount, ['branch' => $this->ctx['branch_id']] + $dimensions),
            new ManualJournalLine($this->ctx['accounts'][$creditRole], Side::Credit, $amount, ['branch' => $this->ctx['branch_id']] + $dimensions),
        ]), $this->maker);
        $journals->submit($journal->id, $this->maker);
        $journals->approve($journal->id, $this->checker);
    };
    // A policy issued on 1 September with VAT, half its premium received, a receipt left in suspense.
    $this->business = function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 11_500_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $receipts = app(ReceiptService::class);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 5_000_000, 'BDT', CarbonImmutable::parse('2026-09-10'), null, 'r1',
            [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), 5_000_000)]), $this->world['admin']);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 70_000, 'BDT', CarbonImmutable::parse('2026-09-12'), null, '??', []),
            $this->world['admin']);

        return $policy->id;
    };
    $this->run = function (): array {
        $close = app(PeriodCloseService::class);
        $runId = $close->start($this->september, $this->world['admin']);
        $results = [];
        foreach (['premium_earning', 'suspense_review', 'upr_reconciliation', 'suspense_reconciliation', 'vat_reconciliation', 'stamp_duty_reconciliation'] as $code) {
            $task = (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', $code)->value('id');
            $close->execute($task, $this->world['admin']);
            $row = DB::table('period_close_tasks')->where('id', $task)->first(['status', 'result']);
            $results[$code] = ['status' => (string) $row?->status] + (array) (json_decode((string) $row?->result, true)['details'] ?? []);
        }

        return $results;
    };
});

it('reconciles unearned premium, suspense, VAT and stamp duty to the GL before the trial balance', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->business)();
        // A VAT payment to the government by manual journal (no policy) is outside the VAT register.
        ($this->journal)('premium_tax_payable', 'bank_main', 400_000);
        $results = ($this->run)();

        $tax = (int) DB::table('policies')->sum('tax_minor');
        expect(array_column($results, 'status', null))->toBe(['done', 'done', 'done', 'done', 'done', 'done'])
            ->and($results['upr_reconciliation'])->toMatchArray(['subledger_minor' => 10_000_000 - (int) DB::table('premium_earning_ledger')->sum('earned_minor'), 'variance_minor' => 0])
            ->and($results['suspense_reconciliation'])->toMatchArray(['subledger_minor' => 70_000, 'gl_minor' => 70_000, 'variance_minor' => 0])
            ->and($results['vat_reconciliation'])->toMatchArray(['subledger_minor' => $tax, 'gl_minor' => $tax, 'variance_minor' => 0])
            ->and($tax)->toBe(1_500_000)
            ->and($results['stamp_duty_reconciliation'])->toMatchArray(['status' => 'done']);
        expect(DB::table('reconciliation_runs')->whereIn('subledger', ['unearned_premium', 'premium_tax', 'stamp_duty', 'suspense'])->where('status', 'variance')->count())->toBe(0);
    });
});

it('blocks the VAT, stamp duty and unearned premium tasks on a variance, and the lock sees it', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = ($this->business)();
        // VAT and stamp duty credited against the policy with no policy transaction behind them, and a manual journal on the unearned premium reserve.
        ($this->journal)('rounding_difference', 'premium_tax_payable', 12_345, ['policy' => $policy]);
        ($this->journal)('rounding_difference', 'stamp_duty_payable', 5_000, ['policy' => $policy]);
        ($this->journal)('rounding_difference', 'unearned_premium', 7_000);
        $results = ($this->run)();

        expect($results['vat_reconciliation'])->toMatchArray(['status' => 'blocked', 'variance_minor' => -12_345])
            ->and($results['stamp_duty_reconciliation'])->toMatchArray(['status' => 'blocked', 'subledger_minor' => 0, 'gl_minor' => 5_000, 'variance_minor' => -5_000])
            ->and($results['upr_reconciliation'])->toMatchArray(['status' => 'blocked', 'variance_minor' => -7_000])
            ->and($results['suspense_reconciliation']['status'])->toBe('done');
        // The exception drills to the policy (VAT) and to the unattributed journal (unearned premium).
        $vatRun = (string) $results['vat_reconciliation']['reconciliation_run_id'];
        expect(DB::table('reconciliation_exceptions')->where('run_id', $vatRun)->get(['object_type', 'object_id', 'expected_minor', 'actual_minor'])->map(fn (object $e): array => (array) $e)->all())
            ->toBe([['object_type' => 'policy', 'object_id' => $policy, 'expected_minor' => 1_500_000, 'actual_minor' => 1_512_345]])
            ->and(DB::table('reconciliation_exceptions')->where('run_id', (string) $results['upr_reconciliation']['reconciliation_run_id'])->where('object_type', 'journal')->count())->toBe(1);

        expect(app(ReconciliationService::class)->currentVariances($this->september))->toMatchArray(['premium_tax' => -12_345, 'stamp_duty' => -5_000, 'unearned_premium' => -7_000]);
        $trialBalance = (string) DB::table('period_close_tasks')->where('code', 'trial_balance')->value('id');
        expect(thrownBy(fn () => app(PeriodCloseService::class)->execute($trialBalance, $this->world['admin']), App\Modules\Platform\Exceptions\BusinessRuleViolation::class)->reasonCode)->toBe('DEPENDENCIES_OPEN');
    });
});
