<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/**
 * Reason codes: INVALID_PERIOD_TRANSITION, REASON_REQUIRED, CLOSE_TASKS_OPEN, RECONCILIATION_VARIANCE, and since slice 2.1b
 * PERIOD_HAS_PENDING_DOCUMENTS (details.pending lists them), PERIOD_NOT_ENDED, PERIOD_LAST_DAY_NOT_REACHED, EARLY_LOCK_REASON_REQUIRED.
 */
final class PeriodTransitionException extends AccountingException
{
    /** @param array<string, mixed> $details machine-readable detail of the refusal, returned with the reason in JSON */
    public function __construct(string $reasonCode, string $message = '', public readonly array $details = [])
    {
        parent::__construct($reasonCode, $message);
    }
}
