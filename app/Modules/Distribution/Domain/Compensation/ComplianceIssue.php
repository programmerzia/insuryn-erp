<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** Why a producer was not paid on a trigger (design note §2 step 1 "compliance exception logged"). The policy itself is never affected. */
final readonly class ComplianceIssue
{
    public function __construct(
        public string $producerId,
        public string $reasonCode,
        public string $message,
    ) {}
}
