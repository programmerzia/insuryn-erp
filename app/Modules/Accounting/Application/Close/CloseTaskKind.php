<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

/** How the close workflow performs a task. */
enum CloseTaskKind: string
{
    /** Done by a business context's CloseTaskCheck. */
    case Check = 'check';
    /** A subledger reconciliation run; blocked on variance. */
    case Reconciliation = 'reconciliation';
    /** A person confirms the work was done (note recorded). */
    case Confirmation = 'confirmation';
    /** Gap fix GA-15: income and expense closed to retained earnings (YearEndClose), in a fiscal year's last month. */
    case YearEndClose = 'year_end_close';
    case TrialBalance = 'trial_balance';
    case FinancialStatements = 'financial_statements';
    case SignOff = 'sign_off';
    case PeriodLock = 'period_lock';
}
