<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use RuntimeException;

/** Reason codes: NOT_PENDING, APPROVER_ALREADY_DECIDED, REASON_REQUIRED, INVALID_POLICY, NO_HANDLER. */
final class ApprovalException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
