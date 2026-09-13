<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Enums;

/**
 * What a rate table's rows hold (Phase 3 design §1, DECISION D-20 for the units). Integers only:
 * - `rate_pct`: `value_bp` in basis points of a percent (1500 = 15.00 %), used with `pct(base, rate)`;
 * - `rate_pm`: `value_bp` in hundredths of a per mille (250 = 2.50 ‰), used with `per_mille(base, rate)`;
 * - `flat`: `value_minor`, an amount in minor units;
 * - `band`: rows are half-open integer bands [band_from, band_to) with a `band_label` (for `band()`) and optionally a value (for `band_value()`).
 */
enum RateValueType: string
{
    case RatePct = 'rate_pct';
    case RatePerMille = 'rate_pm';
    case Flat = 'flat';
    case Band = 'band';
}
