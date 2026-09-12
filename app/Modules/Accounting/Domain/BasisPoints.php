<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/** Integer percentage arithmetic on minor units (design §3.2). 1500 bp = 15%. Never floats. */
final class BasisPoints
{
    private const WHOLE = 10_000;

    /** Share of $minorUnits at $basisPoints, rounded half-even (banker's rounding). */
    public static function of(int $minorUnits, int $basisPoints): int
    {
        $scaled = $minorUnits * $basisPoints; // exact for realistic magnitudes (< 9.2e18)
        $quotient = intdiv($scaled, self::WHOLE);
        $twiceRemainder = abs($scaled % self::WHOLE) * 2;

        $roundsAway = $twiceRemainder > self::WHOLE
            || ($twiceRemainder === self::WHOLE && $quotient % 2 !== 0);

        return $roundsAway ? $quotient + ($scaled >= 0 ? 1 : -1) : $quotient;
    }
}
