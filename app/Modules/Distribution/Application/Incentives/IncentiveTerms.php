<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Incentives;

use App\Modules\Distribution\Domain\Incentives\IncentiveTiers;
use Carbon\CarbonImmutable;

/** A plan whose period ends on the run date, with the period and who it applies to. */
final readonly class IncentiveTerms
{
    /** @param list<string> $producerIds active producers the plan applies to at the period end */
    public function __construct(
        public string $planId,
        public string $code,
        public string $metric,
        public string $periodType,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public array $producerIds,
        public ?string $withholdingJurisdiction,
        public ?string $withholdingTaxType,
        private IncentiveTiers $tiers,
    ) {}

    /** @return array{achievement_bp: int, tier: int|null, bonus_minor: int} */
    public function bonusFor(int $targetValue, int $actualValue): array
    {
        return $this->tiers->bonusFor($targetValue, $actualValue);
    }
}
