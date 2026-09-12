<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain;

use Carbon\CarbonImmutable;

/** Net premium earned over [start, end] (both inclusive): the original premium, or an endorsement delta from its effective date. */
final readonly class EarningLayer
{
    public function __construct(
        public int $netMinor,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}
}
