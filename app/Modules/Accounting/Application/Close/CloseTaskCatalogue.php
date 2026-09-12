<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

/**
 * Design §5.7 tasks built in Phase 1A (5 claims recon arrives with claims; 7 AP/AR, 9 depreciation, 10 FX revaluation, 11 DAC and 12 tax
 * computation are LATER or not in the slice list). "Depends 1–12" for the trial balance means every earlier task that exists.
 * Permissions: the catalogue has no close-specific permissions, so each task uses the permission of its owner's work (interpretation).
 * Skippable: only tasks whose blocking condition the design lets a person waive with a reason (suspense review) or that are
 * confirmations (accruals).
 */
final class CloseTaskCatalogue
{
    /** @return list<CloseTaskDefinition> */
    public function tasks(): array
    {
        $beforeTrialBalance = ['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'commission_reconciliation', 'accruals'];

        return [
            new CloseTaskDefinition(1, 'premium_earning', CloseTaskKind::Check, [], 'system', 'periods.soft_lock'),
            new CloseTaskDefinition(2, 'suspense_review', CloseTaskKind::Check, [], 'branch_accountant', 'receipt.allocate', skippable: true),
            new CloseTaskDefinition(3, 'bank_reconciliation', CloseTaskKind::Check, [], 'treasury', 'bank.match'),
            new CloseTaskDefinition(4, 'premium_reconciliation', CloseTaskKind::Reconciliation, ['premium_earning'], 'accounting', 'periods.soft_lock', subledger: 'premium'),
            new CloseTaskDefinition(6, 'commission_reconciliation', CloseTaskKind::Reconciliation, ['premium_earning'], 'accounting', 'periods.soft_lock', subledger: 'commission'),
            new CloseTaskDefinition(8, 'accruals', CloseTaskKind::Confirmation, [], 'accounting', 'accounting.create_manual_journal', skippable: true),
            new CloseTaskDefinition(13, 'trial_balance', CloseTaskKind::TrialBalance, $beforeTrialBalance, 'finance_manager', 'periods.soft_lock'),
            new CloseTaskDefinition(14, 'financial_statements', CloseTaskKind::FinancialStatements, ['trial_balance'], 'system', 'reports.financial'),
            new CloseTaskDefinition(15, 'sign_off', CloseTaskKind::SignOff, ['trial_balance', 'financial_statements'], 'finance_manager', 'periods.lock'),
            new CloseTaskDefinition(16, 'period_lock', CloseTaskKind::PeriodLock, ['sign_off'], 'finance_manager', 'periods.lock'),
        ];
    }

    public function find(string $code): CloseTaskDefinition
    {
        foreach ($this->tasks() as $task) {
            if ($task->code === $code) {
                return $task;
            }
        }

        throw new \LogicException("Unknown close task {$code}.");
    }
}
