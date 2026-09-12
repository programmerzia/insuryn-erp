<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/**
 * Design §5.7: a month-end close task whose work or blocking condition belongs to a business context (premium earning, suspense
 * review, bank reconciliation, …). Implementations are container-tagged with this interface; the close workflow finds them by task code.
 */
interface CloseTaskCheck
{
    /** The period_close_tasks.code this check performs. */
    public function taskCode(): string;

    /** Does the task's work (idempotently) and reports whether its blocking condition is clear. */
    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult;
}
