<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain;

use InvalidArgumentException;

/** Integer premium arithmetic in minor units with half-even rounding — never floats (CONTEXT.md non-negotiable #5). */
final class PremiumMath
{
    /** round_half_even($numerator / $denominator) for a non-negative numerator and positive denominator. */
    public static function divideHalfEven(int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new InvalidArgumentException('divideHalfEven needs a non-negative numerator and a positive denominator.');
        }
        $quotient = intdiv($numerator, $denominator);
        $twiceRemainder = ($numerator % $denominator) * 2;

        return $twiceRemainder > $denominator || ($twiceRemainder === $denominator && $quotient % 2 === 1) ? $quotient + 1 : $quotient;
    }

    /** $amount × $numerator / $denominator, half-even, keeping the sign of $amount. */
    public static function prorate(int $amount, int $numerator, int $denominator): int
    {
        $share = self::divideHalfEven(abs($amount) * $numerator, $denominator);

        return $amount < 0 ? -$share : $share;
    }

    /**
     * Splits a premium into net and tax (design §4.1 "gross incl. 15% VAT", tax mode split_from_gross).
     *
     * @return array{gross: int, net: int, tax: int}
     */
    public static function splitTax(int $premium, int $rateBp, bool $inclusive): array
    {
        if ($rateBp === 0) {
            return ['gross' => $premium, 'net' => $premium, 'tax' => 0];
        }
        if ($inclusive) {
            $net = self::prorate($premium, 10_000, 10_000 + $rateBp);

            return ['gross' => $premium, 'net' => $net, 'tax' => $premium - $net];
        }
        $tax = self::prorate($premium, $rateBp, 10_000);

        return ['gross' => $premium + $tax, 'net' => $premium, 'tax' => $tax];
    }
}
