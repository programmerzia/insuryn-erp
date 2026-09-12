<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use Illuminate\Support\Facades\DB;

/** Performs one close task's work (design §5.7) and reports passed or blocked. The period lock itself is done by PeriodCloseService. */
final class CloseTaskExecutor
{
    /**
     * @param iterable<CloseTaskCheck> $checks
     * @param iterable<SubledgerReconciler> $reconcilers
     */
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly FiscalPeriodService $periods,
        private readonly LedgerQuery $ledger,
        private readonly iterable $checks,
        private readonly iterable $reconcilers,
    ) {}

    public function execute(CloseTaskDefinition $task, FiscalPeriodView $period, string $closeRunId, string $actorUserId, ?string $note): CloseCheckResult
    {
        return match ($task->kind) {
            CloseTaskKind::Check => $this->check($task, $period, $actorUserId),
            CloseTaskKind::Reconciliation => $this->reconcile($task, $period),
            CloseTaskKind::Confirmation => CloseCheckResult::passed('Confirmed', ['note' => $note]),
            CloseTaskKind::TrialBalance => $this->trialBalance($period, $actorUserId),
            CloseTaskKind::FinancialStatements => $this->financialStatements($period),
            CloseTaskKind::SignOff => $this->signOff($closeRunId, $period, $actorUserId),
            CloseTaskKind::PeriodLock => throw new \LogicException('The period lock is performed by PeriodCloseService.'),
        };
    }

    private function check(CloseTaskDefinition $task, FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        foreach ($this->checks as $check) {
            if ($check->taskCode() === $task->code) {
                return $check->check($period, $actorUserId);
            }
        }

        return CloseCheckResult::blocked("No check is registered for task {$task->code}.");
    }

    private function reconcile(CloseTaskDefinition $task, FiscalPeriodView $period): CloseCheckResult
    {
        foreach ($this->reconcilers as $reconciler) {
            if ($reconciler->subledger() !== $task->subledger) {
                continue;
            }
            $runId = $this->reconciliation->run($reconciler, $period->id);
            $run = $runId === null ? null : DB::table('reconciliation_runs')->where('id', $runId)->first(['status', 'variance_minor', 'subledger_balance_minor', 'gl_balance_minor']);
            if ($run === null) {
                return CloseCheckResult::blocked("Subledger {$task->subledger} has no control account mapped.");
            }
            $details = ['reconciliation_run_id' => $runId, 'subledger_minor' => (int) $run->subledger_balance_minor, 'gl_minor' => (int) $run->gl_balance_minor,
                'variance_minor' => (int) $run->variance_minor];

            return $run->status === 'clean'
                ? CloseCheckResult::passed("Subledger {$task->subledger} reconciles to the GL.", $details)
                : CloseCheckResult::blocked("Subledger {$task->subledger} differs from the GL by {$run->variance_minor}.", $details);
        }

        return CloseCheckResult::blocked("No reconciler is registered for subledger {$task->subledger}.");
    }

    /** Task 13: soft-locks the period (design §5.7), then checks the trial balance balances. */
    private function trialBalance(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        if ($period->status === 'open') {
            $this->periods->softLock($period->id, $actorUserId);
        }
        $rows = $this->ledger->trialBalance($period->entityId, $period->bookId, $period->ends);
        $debit = array_sum(array_column($rows, 'debit'));
        $credit = array_sum(array_column($rows, 'credit'));
        $details = ['as_of' => $period->ends->toDateString(), 'accounts' => count($rows), 'debit_minor' => $debit, 'credit_minor' => $credit];

        return $debit === $credit ? CloseCheckResult::passed('Trial balance balances.', $details) : CloseCheckResult::blocked('Trial balance does not balance.', $details);
    }

    /**
     * Task 14: headline figures of the balance sheet and profit and loss as of the period end. Income and expense are cumulative (there is
     * no year-end close into retained earnings yet), so assets = liabilities + equity + net profit.
     */
    private function financialStatements(FiscalPeriodView $period): CloseCheckResult
    {
        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
        foreach ($this->ledger->trialBalance($period->entityId, $period->bookId, $period->ends) as $row) {
            $debitNormal = in_array($row['type'], ['asset', 'expense'], true);
            $totals[$row['type']] = ($totals[$row['type']] ?? 0) + ($debitNormal ? $row['debit'] - $row['credit'] : $row['credit'] - $row['debit']);
        }

        return CloseCheckResult::passed('Financial statements generated.', ['as_of' => $period->ends->toDateString(),
            'assets_minor' => $totals['asset'], 'liabilities_minor' => $totals['liability'], 'equity_minor' => $totals['equity'],
            'income_minor' => $totals['income'], 'expense_minor' => $totals['expense'], 'net_profit_minor' => $totals['income'] - $totals['expense']]);
    }

    /** Task 15: every other task done or skipped; the trial balance and statements are regenerated so late soft-locked postings are reflected. */
    private function signOff(string $closeRunId, FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $open = DB::table('period_close_tasks')->where('close_run_id', $closeRunId)->whereNotIn('code', ['sign_off', 'period_lock'])
            ->whereNotIn('status', ['done', 'skipped'])->orderBy('order_no')->pluck('code')->all();
        if ($open !== []) {
            return CloseCheckResult::blocked('Tasks not done or skipped: '.implode(', ', $open).'.', ['open_tasks' => $open]);
        }
        $trialBalance = $this->trialBalance($period, $actorUserId);
        foreach (['trial_balance' => $trialBalance, 'financial_statements' => $this->financialStatements($period)] as $code => $result) {
            DB::table('period_close_tasks')->where('close_run_id', $closeRunId)->where('code', $code)
                ->update(['result' => json_encode(['summary' => $result->summary, 'details' => $result->details], JSON_THROW_ON_ERROR)]);
        }

        return $trialBalance->passed ? CloseCheckResult::passed('Close checklist signed off.') : CloseCheckResult::blocked('Trial balance no longer balances.');
    }
}
