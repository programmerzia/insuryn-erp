<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

/** One beneficiary's commission on a trigger, as the commission subledger records it. */
final readonly class CommissionAward
{
    public function __construct(
        public string $producerId,
        public string $role,
        public ?string $levelCode,
        public ?string $ruleId,
        public int $rateBp,
        public int $amountMinor,
        public int $withholdingMinor,
        public bool $conditional,
    ) {}
}
