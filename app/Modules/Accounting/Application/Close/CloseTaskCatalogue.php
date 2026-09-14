<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\CloseTaskContributor;

/**
 * Design §5.7 tasks built in Phases 1A/1B (7 AP/AR, 9 depreciation, 10 FX revaluation, 11 DAC and 12 tax
 * computation are LATER or not in the slice list). "Depends 1–12" for the trial balance means every earlier task that exists.
 * Permissions: the catalogue has no close-specific permissions, so each task uses the permission of its owner's work (interpretation).
 * Skippable: only tasks whose blocking condition the design lets a person waive with a reason (suspense review) or that are
 * confirmations (accruals).
 */
final class CloseTaskCatalogue
{
    /** Gap fix GA-09: tasks that post journals (premium earning's events, the year-end closing journal), so the checklist previews them. */
    public const POSTING_TASKS = ['premium_earning', 'ri_unearned_premium', 'year_end_close'];

    /** Market gap G5: tasks listed only where a ConditionalCloseTask says they apply. */
    public const CONDITIONAL_TASKS = ['technical_provisions'];

    /** @param iterable<CloseTaskContributor> $contributors addendum §B.2.8 (PD-8): tasks business contexts add for the entities that use them */
    public function __construct(private readonly iterable $contributors = []) {}

    /**
     * The tasks of a close run. Gap fix GA-15: the year-end close task only in the close of a fiscal year's last month ($yearEnd); the other
     * months' runs do not list it.
     *
     * @param list<string> $conditional codes of conditional tasks that apply to the run (ConditionalCloseTask)
     * @return list<CloseTaskDefinition>
     */
    public function tasks(bool $yearEnd = false, array $conditional = [], ?string $entityId = null): array
    {
        // Market gap G5 (D-111): the quarterly technical provisions, only in the runs a ConditionalCloseTask claims (a quarter's last month).
        $provisions = in_array('technical_provisions', $conditional, true);
        $contributed = [];
        foreach ($this->contributors as $contributor) {
            if ($entityId !== null && $contributor->appliesTo($entityId)) {
                array_push($contributed, ...$contributor->definitions());
            }
        }
        $contributedCodes = array_map(fn (CloseTaskDefinition $task): string => $task->code, $contributed);
        // Gap fix GA-43: the unearned premium, suspense, VAT payable and stamp duty payable reconciliations run before the trial balance too.
        $reconciliations = ['premium_reconciliation', 'claims_reconciliation', 'commission_reconciliation', 'upr_reconciliation', 'suspense_reconciliation',
            'vat_reconciliation', 'stamp_duty_reconciliation',
            // Reinsurance MVP (G4): reinsurers' share of unearned premium, then the amounts due to and from reinsurers.
            'ri_unearned_premium', 'ri_balances_reconciliation', 'ri_claims_reconciliation'];
        $beforeYearEnd = ['premium_earning', 'suspense_review', 'bank_reconciliation', ...$reconciliations, 'accruals', ...($provisions ? ['technical_provisions'] : []), ...$contributedCodes];
        $beforeTrialBalance = $yearEnd ? [...$beforeYearEnd, 'year_end_close'] : $beforeYearEnd;

        return [
            new CloseTaskDefinition(1, 'premium_earning', CloseTaskKind::Check, [], 'system', 'periods.soft_lock'),
            new CloseTaskDefinition(2, 'suspense_review', CloseTaskKind::Check, [], 'branch_accountant', 'receipt.allocate', skippable: true),
            new CloseTaskDefinition(3, 'bank_reconciliation', CloseTaskKind::Check, [], 'treasury', 'bank.match'),
            new CloseTaskDefinition(4, 'premium_reconciliation', CloseTaskKind::Reconciliation, ['premium_earning'], 'accounting', 'periods.soft_lock', subledger: 'premium'),
            new CloseTaskDefinition(5, 'claims_reconciliation', CloseTaskKind::Reconciliation, [], 'claims_accounting', 'periods.soft_lock', subledger: 'claims'),
            new CloseTaskDefinition(6, 'commission_reconciliation', CloseTaskKind::Reconciliation, ['premium_earning'], 'accounting', 'periods.soft_lock', subledger: 'commission'),
            // Gap fix GA-43 (D-83): §5.7 has no row for these; they take the free numbers after the reconciliations (7, AP/AR, is LATER and not listed) and
            // around the accruals, so the checklist reads reconciliations → accruals → duties. The number orders the checklist; it is not the design's row number.
            new CloseTaskDefinition(7, 'upr_reconciliation', CloseTaskKind::Reconciliation, ['premium_earning'], 'accounting', 'periods.soft_lock', subledger: 'unearned_premium'),
            new CloseTaskDefinition(8, 'accruals', CloseTaskKind::Confirmation, [], 'accounting', 'accounting.create_manual_journal', skippable: true),
            new CloseTaskDefinition(9, 'suspense_reconciliation', CloseTaskKind::Reconciliation, ['suspense_review'], 'accounting', 'periods.soft_lock', subledger: 'suspense'),
            new CloseTaskDefinition(10, 'vat_reconciliation', CloseTaskKind::Reconciliation, [], 'accounting', 'periods.soft_lock', subledger: 'premium_tax'),
            new CloseTaskDefinition(11, 'stamp_duty_reconciliation', CloseTaskKind::Reconciliation, [], 'accounting', 'periods.soft_lock', subledger: 'stamp_duty'),
            // Market gap G5: the quarter's technical provisions run is posted before the trial balance (the task shares number 12 with the year-end close, which follows it).
            ...($provisions ? [new CloseTaskDefinition(12, 'technical_provisions', CloseTaskKind::Check, [], 'finance_manager', 'provisions.run')] : []),
            ...$contributed,
            // Reinsurance MVP (G4, DECISION D-108): they share order 11 with the stamp duty reconciliation (order numbers need not be unique) and run before the trial balance.
            new CloseTaskDefinition(11, 'ri_unearned_premium', CloseTaskKind::Check, ['premium_earning'], 'accounting', 'periods.soft_lock'),
            new CloseTaskDefinition(11, 'ri_balances_reconciliation', CloseTaskKind::Reconciliation, [], 'accounting', 'periods.soft_lock', subledger: 'ri_payable'),
            new CloseTaskDefinition(11, 'ri_claims_reconciliation', CloseTaskKind::Reconciliation, [], 'accounting', 'periods.soft_lock', subledger: 'ri_claims'),
            // Gap fix GA-15 (D-81): in the fiscal year's last month, once everything that posts to income and expense is done.
            ...($yearEnd ? [new CloseTaskDefinition(12, 'year_end_close', CloseTaskKind::YearEndClose, $beforeYearEnd, 'finance_manager', 'periods.lock')] : []),
            new CloseTaskDefinition(13, 'trial_balance', CloseTaskKind::TrialBalance, $beforeTrialBalance, 'finance_manager', 'periods.soft_lock'),
            new CloseTaskDefinition(14, 'financial_statements', CloseTaskKind::FinancialStatements, ['trial_balance'], 'system', 'reports.financial'),
            new CloseTaskDefinition(15, 'sign_off', CloseTaskKind::SignOff, ['trial_balance', 'financial_statements'], 'finance_manager', 'periods.lock'),
            new CloseTaskDefinition(16, 'period_lock', CloseTaskKind::PeriodLock, ['sign_off'], 'finance_manager', 'periods.lock'),
        ];
    }

    public function find(string $code): CloseTaskDefinition
    {
        foreach ($this->tasks(yearEnd: true, conditional: ['technical_provisions']) as $task) {
            if ($task->code === $code) {
                return $task;
            }
        }
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->definitions() as $task) {
                if ($task->code === $code) {
                    return $task;
                }
            }
        }

        throw new \LogicException("Unknown close task {$code}.");
    }
}
