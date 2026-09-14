<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\RatedPremium;
use App\Modules\Insurance\Rating\Domain\RatingResult;

/**
 * An endorsement's re-rating (slice R7, design §2 step 5): the rating in force before it (the policy's frozen issue rating, or the latest re-rated endorsement),
 * the new rating — on the original plan version, or the tariff in force when the product version says `endorsement_uses_current_tariff` — and the premium change
 * charged (full difference, or pro rata for the days left, A-119).
 */
final readonly class EndorsementRating
{
    public const ORIGINAL_PLAN = 'original_plan';

    public const CURRENT_TARIFF = 'current_tariff';

    public function __construct(
        public RatingResult $before,
        public RatingResult $after,
        public string $basis,
        public RatedPremium $change,
        public int $daysCharged,
        public int $daysInTerm,
        public bool $proRata,
    ) {}

    public function isEmpty(): bool
    {
        return $this->change->netMinor === 0 && $this->change->taxMinor === 0 && $this->change->stampDutyMinor === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'basis' => $this->basis, 'before' => $this->before->toArray(), 'after' => $this->after->toArray(),
            'change' => ['net_minor' => $this->change->netMinor, 'tax_minor' => $this->change->taxMinor, 'stamp_duty_minor' => $this->change->stampDutyMinor,
                'gross_minor' => $this->change->grossMinor()],
            'pro_rata' => $this->proRata, 'days_charged' => $this->daysCharged, 'days_in_term' => $this->daysInTerm,
        ];
    }
}
