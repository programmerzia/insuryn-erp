<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** What commission is calculated on (design note §2): premium received (allocation) or written (issue), in minor units, for a policy year of a product. */
final readonly class Trigger
{
    public function __construct(
        public string $basis,
        public int $baseMinor,
        public int $policyYear,
        public string $productId,
        public string $productClass,
    ) {}
}
