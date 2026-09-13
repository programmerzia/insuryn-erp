<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** Commission for one beneficiary on one trigger: `direct` for the seller, `override` for a level above it. Conditional lines wait for the statement run. */
final readonly class CommissionLine
{
    public function __construct(
        public Beneficiary $beneficiary,
        public string $role,
        public ?string $levelCode,
        public ?string $ruleId,
        public int $rateBp,
        public int $amountMinor,
        public int $withholdingMinor,
        public bool $conditional,
    ) {}
}
