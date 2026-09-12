<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/** Reason codes: INVALID_PERIOD_TRANSITION, REASON_REQUIRED, CLOSE_TASKS_OPEN, RECONCILIATION_VARIANCE. */
final class PeriodTransitionException extends AccountingException {}
