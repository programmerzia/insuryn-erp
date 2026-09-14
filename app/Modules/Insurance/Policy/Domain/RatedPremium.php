<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain;

use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * The premium a policy charges from a rating result (slice R7, design §2 step 4 "POLICY_ISSUED with gross/net/duties from rating_result"): net premium; stamp duty
 * (duties with code `stamp`) on its own account (D-37); every other duty — VAT and levies — as the tax the premium tax account holds. gross = net + tax + stamp duty.
 * ASSUMPTION: A-118 (the mapping), A-119 (pro rata in `minus`).
 */
final readonly class RatedPremium
{
    public function __construct(
        public int $netMinor,
        public int $taxMinor,
        public int $stampDutyMinor,
    ) {}

    /** @throws BusinessRuleViolation RATING_RESULT_INVALID when the result's gross is not its net plus its duties */
    public static function of(RatingResult $result): self
    {
        $stamp = 0;
        $duties = 0;
        foreach ($result->duties as $duty) {
            $duties += $duty['amount_minor'];
            if ($duty['code'] === 'stamp') {
                $stamp += $duty['amount_minor'];
            }
        }
        if ($duties !== $result->dutiesTotalMinor || $result->grossPremiumMinor !== $result->netPremiumMinor + $result->dutiesTotalMinor || $result->netPremiumMinor < 0) {
            throw new BusinessRuleViolation('RATING_RESULT_INVALID', 'The rating result does not add up: gross must be net premium plus duties.');
        }

        return new self($result->netPremiumMinor, $duties - $stamp, $stamp);
    }

    public function grossMinor(): int
    {
        return $this->netMinor + $this->taxMinor + $this->stampDutyMinor;
    }

    /**
     * The change from $before to this premium. Pro rata: net premium and tax for $daysCharged of $daysInTerm (half-even), stamp duty in full (A-119).
     */
    public function minus(self $before, int $daysCharged = 1, int $daysInTerm = 1): self
    {
        return new self(
            PremiumMath::prorate($this->netMinor - $before->netMinor, $daysCharged, $daysInTerm),
            PremiumMath::prorate($this->taxMinor - $before->taxMinor, $daysCharged, $daysInTerm),
            $this->stampDutyMinor - $before->stampDutyMinor,
        );
    }
}
