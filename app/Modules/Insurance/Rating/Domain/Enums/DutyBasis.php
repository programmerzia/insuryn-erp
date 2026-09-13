<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Enums;

/**
 * How a duty is measured (Phase 3 design §1 duties): `pct_of_premium` — `rate_bp` of the net premium, half-even; `flat_per_policy` — `amount_minor`;
 * `per_sum_insured_band` — the `amount_minor` of the band [from, to) the sum insured falls in.
 */
enum DutyBasis: string
{
    case PctOfPremium = 'pct_of_premium';
    case FlatPerPolicy = 'flat_per_policy';
    case PerSumInsuredBand = 'per_sum_insured_band';
}
