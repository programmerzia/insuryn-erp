<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

/** What a beneficiary earned on a policy (or gives back, negative): base, commission and withholding in minor units. */
final readonly class CommissionShare
{
    public function __construct(
        public string $producerId,
        public int $baseMinor,
        public int $amountMinor,
        public int $withholdingMinor,
    ) {}
}
