<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

/** Part of a receipt to apply to one installment, in minor units. */
final readonly class AllocationLine
{
    public function __construct(
        public string $installmentId,
        public int $amountMinor,
    ) {}
}
