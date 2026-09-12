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
    case TrialBalance = 'trial_balance';
    case FinancialStatements = 'financial_statements';
    case SignOff = 'sign_off';
    case PeriodLock = 'period_lock';
}
