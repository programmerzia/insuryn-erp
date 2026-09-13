<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** A producer in the hierarchy snapshot of a trigger: depth 0 sold the business, depth 1 is its parent. `licensed`: valid licence for the product class that day, or no licence needed. */
final readonly class Beneficiary
{
    public function __construct(
        public string $producerId,
        public string $code,
        public string $type,
        public string $status,
        public ?string $levelCode,
        public int $depth,
        public bool $licensed,
    ) {}
}
