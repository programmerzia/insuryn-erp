<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\PremiumEarning;

final readonly class EarningRunResult
{
    public function __construct(
        public int $policiesEarned,
        public int $earnedMinor,
    ) {}
}
