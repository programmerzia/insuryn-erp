<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use RuntimeException;

/** Reason codes: SOD_CONFLICT, AUDITOR_WRITE_PERMISSION. */
final class SodViolation extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $userId,
        public readonly string $permission,
        public readonly string $conflictingPermission,
        public readonly string $ruleCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
