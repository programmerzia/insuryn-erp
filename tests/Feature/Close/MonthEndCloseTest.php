<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/**
 * Design §5.7 month-end close: tasks 1 premium earning, 2 suspense review, 3 bank reconciliation, 4 premium recon, 6 commission recon,
 * 8 accruals, 13 trial balance (soft-lock), 14 financial statements, 15 sign-off, 16 period lock; order and dependencies; blocking
 * conditions; INVARIANT lock refuses open tasks or variance.
 */
beforeEach(function (): void {
    // Slice 2.1b (D-56): a month is locked only once it has ended on the business clock; these cases close September, so they run on 1 October.
    travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    $this->close = fn (): PeriodCloseService => app(PeriodCloseService::class);
    $this->task = fn (string $runId, string $code): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', $code)->value('id');
    $this->status = fn (string $runId): array => DB::table('period_close_tasks')->where('close_run_id', $runId)->orderBy('order_no')->pluck('status', 'code')->all();
    // A policy issued in July, premium received in September.
    $this->business = function (): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);
        $installment = (string) DB::table('installments')->where('policy_id', $policy->id)->value('id');
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 3_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, 'r1', [new AllocationLine($installment, 3_000_000)]), $this->world['admin']);
    };
});

it('starts a close run with the §5.7 tasks in order with their dependencies, once', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['receipt.allocate']);

    asTenant($this->ctx['tenant_id'], function () use ($clerk): void {
        expect(fn () => ($this->close)()->start($this->september, $clerk))->toThrow(PermissionDenied::class);

        $runId = ($this->close)()->start($this->september, $this->world['admin']);
        $tasks = DB::table('period_close_tasks')->where('close_run_id', $runId)->orderBy('order_no')->get(['code', 'order_no', 'depends_on', 'owner_role', 'status']);

        expect($tasks->map(fn (object $t): array => [(int) $t->order_no, (string) $t->code, json_decode((string) $t->depends_on, true)])->all())->toBe([
            [1, 'premium_earning', []], [2, 'suspense_review', []], [3, 'bank_reconciliation', []], [4, 'premium_reconciliation', ['premium_earning']],
            [5, 'claims_reconciliation', []], [6, 'commission_reconciliation', ['premium_earning']],
            // Gap fix GA-43: unearned premium, suspense, VAT payable and stamp duty payable reconcile before the trial balance too.
            [7, 'upr_reconciliation', ['premium_earning']], [8, 'accruals', []], [9, 'suspense_reconciliation', ['suspense_review']], [10, 'vat_reconciliation', []],
            [11, 'stamp_duty_reconciliation', []],
            [13, 'trial_balance', ['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'claims_reconciliation', 'commission_reconciliation',
                'upr_reconciliation', 'suspense_reconciliation', 'vat_reconciliation', 'stamp_duty_reconciliation', 'accruals']],
            [14, 'financial_statements', ['trial_balance']], [15, 'sign_off', ['trial_balance', 'financial_statements']], [16, 'period_lock', ['sign_off']],
        ])
            ->and($tasks->pluck('status')->unique()->all())->toBe(['pending'])
            ->and(thrownBy(fn () => ($this->close)()->start($this->september, $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('CLOSE_ALREADY_RUNNING');
    });
});

it('closes a clean month end to end: earning, checks, soft-lock at the trial balance, statements, sign-off, lock', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->business)();
        $close = ($this->close)();
        $runId = $close->start($this->september, $this->world['admin']);

        expect(thrownBy(fn () => $close->execute(($this->task)($runId, 'premium_reconciliation'), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('DEPENDENCIES_OPEN');

        foreach (['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'claims_reconciliation', 'commission_reconciliation',
            'upr_reconciliation', 'suspense_reconciliation', 'vat_reconciliation', 'stamp_duty_reconciliation'] as $code) {
            $close->execute(($this->task)($runId, $code), $this->world['admin']);
        }
        $close->execute(($this->task)($runId, 'accruals'), $this->world['admin'], 'No accruals this month');
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('open')
            ->and(DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'year_end_close')->exists())->toBeFalse(); // September is not the year's last month

        $close->execute(($this->task)($runId, 'trial_balance'), $this->world['admin']);
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('soft_locked');

        foreach (['financial_statements', 'sign_off', 'period_lock'] as $code) {
            $close->execute(($this->task)($runId, $code), $this->world['admin']);
        }

        $earning = json_decode((string) DB::table('period_close_tasks')->where('id', ($this->task)($runId, 'premium_earning'))->value('result'), true);
        $trialBalance = json_decode((string) DB::table('period_close_tasks')->where('id', ($this->task)($runId, 'trial_balance'))->value('result'), true);
        $statements = json_decode((string) DB::table('period_close_tasks')->where('id', ($this->task)($runId, 'financial_statements'))->value('result'), true);

        expect(array_unique(array_values(($this->status)($runId))))->toBe(['done'])
            ->and(DB::table('period_close_runs')->where('id', $runId)->value('status'))->toBe('completed')
            ->and(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('locked')
            ->and($earning['details']['missing_policies'])->toBe([])
            ->and((int) DB::table('premium_earning_ledger')->count())->toBe(1) // monthly: only September's run was executed by the close
            ->and($trialBalance['details']['debit_minor'])->toBe($trialBalance['details']['credit_minor'])
            ->and($statements['details']['income_minor'])->toBe((int) DB::table('premium_earning_ledger')->sum('earned_minor'))
            ->and($statements['details']['net_profit_minor'])->toBe($statements['details']['income_minor'] - $statements['details']['expense_minor'])
            ->and($statements['details']['assets_minor'])->toBe($statements['details']['liabilities_minor'] + $statements['details']['equity_minor'] + $statements['details']['net_profit_minor']);
    });
});

it('blocks on old suspense until it is waived with a reason, and only skippable tasks can be skipped', function (): void {
    config(['erp.close.suspense_max_age_days' => 30]);

    asTenant($this->ctx['tenant_id'], function (): void {
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 50_000, 'BDT',
            CarbonImmutable::parse('2026-08-20'), null, '??', []), $this->world['admin']);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 70_000, 'BDT',
            CarbonImmutable::parse('2026-09-20'), null, '??', []), $this->world['admin']);
        $close = ($this->close)();
        $runId = $close->start($this->september, $this->world['admin']);
        $suspense = ($this->task)($runId, 'suspense_review');

        $close->execute($suspense, $this->world['admin']);
        $result = json_decode((string) DB::table('period_close_tasks')->where('id', $suspense)->value('result'), true);

        expect(DB::table('period_close_tasks')->where('id', $suspense)->value('status'))->toBe('blocked')
            ->and($result['details']['items_over_threshold'])->toBe(1)
            ->and($result['details']['amount_over_threshold_minor'])->toBe(50_000)
            ->and(thrownBy(fn () => $close->skip($suspense, '  ', $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED')
            ->and(thrownBy(fn () => $close->skip(($this->task)($runId, 'bank_reconciliation'), 'no time', $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('TASK_NOT_SKIPPABLE');

        $close->skip($suspense, 'Agent deposit under investigation, ticket 4411', $this->world['admin']);
        expect(DB::table('period_close_tasks')->where('id', $suspense)->value('status'))->toBe('skipped')
            ->and(json_decode((string) DB::table('period_close_tasks')->where('id', $suspense)->value('result'), true)['skip_reason'])->toBe('Agent deposit under investigation, ticket 4411');
    });
});

it('blocks the bank task on unexplained statement lines up to the period end', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $gl = (string) DB::table('accounts')->where('code', '1010')->value('id');
        $bankAccountId = app(BankAccountService::class)->create($this->ctx['entity_id'], $gl, 'City Bank', '****1', 'BDT', $this->world['admin'])->id;
        app(StatementImport::class)->import($bankAccountId, "date,description,reference,amount\n2026-09-30,Charges,,-150.00\n2026-10-02,Charges,,-150.00\n", 's.csv', $this->world['admin']);
        $close = ($this->close)();
        $runId = $close->start($this->september, $this->world['admin']);
        $bank = ($this->task)($runId, 'bank_reconciliation');

        $close->execute($bank, $this->world['admin']);
        expect(DB::table('period_close_tasks')->where('id', $bank)->value('status'))->toBe('blocked')
            ->and(json_decode((string) DB::table('period_close_tasks')->where('id', $bank)->value('result'), true)['details']['unmatched_lines'])->toBe(1);

        app(BankMatcher::class)->explain((string) DB::table('bank_statement_lines')->where('posted_on', '2026-09-30')->value('id'), 'fee', $this->world['admin']);
        $close->execute($bank, $this->world['admin']);
        expect(DB::table('period_close_tasks')->where('id', $bank)->value('status'))->toBe('done');
    });
});

it('blocks the premium reconciliation task on a variance, and the lock stays refused', function (): void {
    $maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);

    asTenant($this->ctx['tenant_id'], function () use ($maker, $checker): void {
        ($this->business)();
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-25'), 'Write-up', JournalKind::Adjustment, 'test', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['premium_receivable'], Side::Debit, 10_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['rounding_difference'], Side::Credit, 10_000, ['branch' => $this->ctx['branch_id']]),
        ]), $maker);
        $journals->submit($journal->id, $maker);
        $journals->approve($journal->id, $checker);
        $close = ($this->close)();
        $runId = $close->start($this->september, $this->world['admin']);
        $close->execute(($this->task)($runId, 'premium_earning'), $this->world['admin']);
        $close->execute(($this->task)($runId, 'premium_reconciliation'), $this->world['admin']);
        $result = json_decode((string) DB::table('period_close_tasks')->where('id', ($this->task)($runId, 'premium_reconciliation'))->value('result'), true);

        expect(DB::table('period_close_tasks')->where('id', ($this->task)($runId, 'premium_reconciliation'))->value('status'))->toBe('blocked')
            ->and($result['details']['variance_minor'])->toBe(-10_000)
            ->and(thrownBy(fn () => $close->execute(($this->task)($runId, 'trial_balance'), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('DEPENDENCIES_OPEN')
            ->and(thrownBy(fn () => $close->skip(($this->task)($runId, 'premium_reconciliation'), 'ignore', $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('TASK_NOT_SKIPPABLE');

        app(FiscalPeriodService::class)->softLock($this->september, $this->world['admin']);
        expect(thrownBy(fn () => app(FiscalPeriodService::class)->lock($this->september, $this->world['admin']), PeriodTransitionException::class)->reasonCode)->toBe('CLOSE_TASKS_OPEN');
    });
});

it('requires each task\'s permission and refuses work on a reopened run', function (): void {
    $accountant = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal']);

    asTenant($this->ctx['tenant_id'], function () use ($accountant): void {
        $close = ($this->close)();
        $runId = $close->start($this->september, $this->world['admin']);

        expect(fn () => $close->execute(($this->task)($runId, 'bank_reconciliation'), $accountant))->toThrow(PermissionDenied::class);
        $close->execute(($this->task)($runId, 'accruals'), $accountant, 'Rent accrued in JV-2026-000123');
        expect(thrownBy(fn () => $close->execute(($this->task)($runId, 'accruals'), $accountant), BusinessRuleViolation::class)->reasonCode)->toBe('TASK_ALREADY_DONE');

        app(FiscalPeriodService::class)->softLock($this->september, $this->world['admin']);
        app(FiscalPeriodService::class)->reopen($this->september, $this->world['admin'], 'late invoice');
        expect(thrownBy(fn () => $close->execute(($this->task)($runId, 'suspense_review'), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('CLOSE_RUN_NOT_ACTIVE')
            ->and($close->start($this->september, $this->world['admin']))->not->toBe($runId);
    });
});

it('drives the close over the API', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $admin = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->world['admin']));

    $runId = Pest\Laravel\actingAs($admin)->postJson("/api/accounting/periods/{$this->september}/close", [], $headers)->assertCreated()->json('data.id');
    $taskId = asTenant($this->ctx['tenant_id'], fn () => ($this->task)((string) $runId, 'accruals'));
    Pest\Laravel\actingAs($admin)->postJson("/api/accounting/close-tasks/{$taskId}/execute", ['note' => 'none'], $headers)->assertOk()->assertJsonPath('data.status', 'done');
    Pest\Laravel\actingAs($admin)->getJson("/api/accounting/close-runs/{$runId}", $headers)->assertOk()->assertJsonPath('data.tasks.7.code', 'accruals')->assertJsonPath('data.tasks.7.status', 'done');
});
