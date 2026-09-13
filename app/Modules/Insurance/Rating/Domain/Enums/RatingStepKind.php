<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Enums;

/**
 * Rating step kinds (Phase 3 design §1). What each step's expression means for the running premium:
 * - base, coverage, loading: the amount is added;
 * - discount: the amount (positive) is taken off;
 * - minimum: the amount is a floor — the premium becomes max(premium, amount);
 * - rounding: the amount is the new premium (e.g. `round_to(running.premium, 100)`);
 * - duty, tax: the amount is a duty on top of the net premium (e.g. `duty('vat')`), not part of it.
 * Steps run in order_no and must keep the phases in order: premium steps, then rounding, then duties and taxes.
 */
enum RatingStepKind: string
{
    case Base = 'base';
    case Coverage = 'coverage';
    case Loading = 'loading';
    case Discount = 'discount';
    case Minimum = 'minimum';
    case Rounding = 'rounding';
    case Duty = 'duty';
    case Tax = 'tax';

    public function phase(): int
    {
        return match ($this) {
            self::Base, self::Coverage, self::Loading, self::Discount, self::Minimum => 1,
            self::Rounding => 2,
            self::Duty, self::Tax => 3,
        };
    }

    public function isDuty(): bool
    {
        return $this->phase() === 3;
    }
}
