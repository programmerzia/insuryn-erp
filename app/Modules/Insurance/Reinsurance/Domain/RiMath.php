<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Domain;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/** Integer arithmetic for cessions: minor units and basis points only, half-even, through BigInteger so large sums insured never overflow or pass through a float. */
final class RiMath
{
    /** $amount × $numerator / $denominator, rounded half-even; 0 when the denominator is 0. */
    public static function ratio(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator === 0 || $amount === 0 || $numerator === 0) {
            return 0;
        }

        return BigInteger::of($amount)->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfEven)->toInt();
    }

    /** $amount × basis points / 10,000. */
    public static function bp(int $amount, int $basisPoints): int
    {
        return self::ratio($amount, $basisPoints, 10_000);
    }

    /** The basis-point share $part is of $whole (0 when $whole is 0), capped at 10,000. */
    public static function shareBp(int $part, int $whole): int
    {
        return $whole <= 0 ? 0 : min(10_000, max(0, self::ratio(10_000, $part, $whole)));
    }

    /**
     * Splits $amount over weights in basis points (summing to 10,000) so the parts add up exactly: each part rounded, the residual on the largest weight.
     *
     * @param array<string, int> $weights key → basis points
     * @return array<string, int>
     */
    public static function split(int $amount, array $weights): array
    {
        $parts = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = self::bp($amount, $weight);
        }
        $total = array_sum($weights);
        if ($parts !== [] && $total === 10_000) {
            $largest = array_keys($weights, max($weights), true)[0];
            $parts[$largest] += $amount - array_sum($parts);
        }

        return $parts;
    }
}
