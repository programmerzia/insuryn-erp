<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** What one beneficiary earned (or gives back, negative) on a policy: base, commission and withholding in minor units. */
final readonly class EarnedShare
{
    public function __construct(
        public string $beneficiaryId,
        public int $baseMinor,
        public int $amountMinor,
        public int $withholdingMinor,
    ) {}
}
