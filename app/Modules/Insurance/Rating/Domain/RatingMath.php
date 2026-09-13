<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

/**
 * Integer arithmetic for rating (CONTEXT.md non-negotiable #5, design INVARIANT "no floats"): every division rounds half-even (banker's rounding,
 * like `BasisPoints`), and a product that would overflow a 64-bit integer is refused instead of silently becoming a float.
 */
final class RatingMath
{
    /** $amount × $numerator / $denominator, half-even, keeping the sign. */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        return self::divide(self::multiply($amount, $numerator), $denominator);
    }

    /** $base at $basisPoints of a percent (1500 = 15 %). */
    public static function pct(int $base, int $basisPoints): int
    {
        return self::mulDiv($base, $basisPoints, 10_000);
    }

    /** $base at a rate in hundredths of a per mille (250 = 2.50 ‰). */
    public static function perMille(int $base, int $rateHundredths): int
    {
        return self::mulDiv($base, $rateHundredths, 100_000);
    }

    /** round_half_even($numerator / $denominator). */
    public static function divide(int $numerator, int $denominator): int
    {
        self::assertInRange($numerator);
        self::assertInRange($denominator);
        if ($denominator === 0) {
            throw new RatingFailed('RATING_DIVISION_BY_ZERO', 'A rating expression divides by zero.');
        }
        if ($denominator < 0) {
            return self::divide(-$numerator, -$denominator);
        }
        $quotient = intdiv($numerator, $denominator);
        $twiceRemainder = abs($numerator % $denominator) * 2;
        $roundsAway = $twiceRemainder > $denominator || ($twiceRemainder === $denominator && $quotient % 2 !== 0);

        return $roundsAway ? $quotient + ($numerator >= 0 ? 1 : -1) : $quotient;
    }

    /** The nearest multiple of $unit, half-even. */
    public static function roundTo(int $value, int $unit): int
    {
        self::assertUnit($unit);

        return self::divide($value, $unit) * $unit;
    }

    /** The smallest multiple of $unit that is not below $value. */
    public static function ceilTo(int $value, int $unit): int
    {
        self::assertUnit($unit);
        $truncated = intdiv($value, $unit) * $unit; // toward zero: the ceiling for negative values, the floor for positive ones

        return $truncated < $value ? $truncated + $unit : $truncated;
    }

    /** The largest multiple of $unit that is not above $value. */
    public static function floorTo(int $value, int $unit): int
    {
        self::assertUnit($unit);
        $truncated = intdiv($value, $unit) * $unit;

        return $truncated > $value ? $truncated - $unit : $truncated;
    }

    public static function multiply(int $left, int $right): int
    {
        self::assertInRange($left);
        self::assertInRange($right);
        if ($left !== 0 && abs($right) > intdiv(PHP_INT_MAX, abs($left))) {
            throw new RatingFailed('RATING_OVERFLOW', 'A rating amount is too large to calculate exactly.');
        }

        return $left * $right;
    }

    public static function add(int $left, int $right): int
    {
        self::assertInRange($left);
        self::assertInRange($right);
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < -PHP_INT_MAX - $right)) {
            throw new RatingFailed('RATING_OVERFLOW', 'A rating amount is too large to calculate exactly.');
        }

        return $left + $right;
    }

    private static function assertInRange(int $value): void
    {
        if ($value === PHP_INT_MIN) {
            throw new RatingFailed('RATING_OVERFLOW', 'A rating amount is too large to calculate exactly.');
        }
    }

    private static function assertUnit(int $unit): void
    {
        if ($unit <= 0) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', 'Rounding needs a positive unit.');
        }
    }
}
