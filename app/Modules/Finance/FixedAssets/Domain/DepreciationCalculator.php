<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Domain;

use Carbon\CarbonImmutable;

/**
 * Design addendum v2 §B.7: one month's depreciation of one asset, in integer minor units with half-even rounding (no floats).
 * - Straight line: (cost − residual) ÷ useful life each month; the last month of the life absorbs the rounding residual, so Σ = cost − residual exactly.
 * - Reducing balance: net book value at the start of the month × annual rate ÷ 12, never below the residual value.
 * Depreciation starts in the month of acquisition (ASSUMPTION A-273); the month of disposal is not depreciated (design example, CQ-I8).
 */
final class DepreciationCalculator
{
    /**
     * @param int $accumulatedBefore accumulated depreciation before this month (opening accumulated included)
     * @return int the amount for the month ending $periodEnds, 0 when nothing is due
     */
    public static function monthly(string $method, int $costMinor, int $residualMinor, ?int $lifeMonths, ?int $rateBp, int $accumulatedBefore,
        CarbonImmutable $acquiredOn, CarbonImmutable $periodEnds): int
    {
        $remaining = $costMinor - $residualMinor - $accumulatedBefore;
        if ($remaining <= 0 || $acquiredOn->greaterThan($periodEnds)) {
            return 0;
        }
        if ($method === 'reducing_balance') {
            return min($remaining, self::halfEven(($costMinor - $accumulatedBefore) * (int) $rateBp, 120_000));
        }
        $life = max(1, (int) $lifeMonths);
        $monthIndex = ($periodEnds->year - $acquiredOn->year) * 12 + $periodEnds->month - $acquiredOn->month + 1;

        return $monthIndex >= $life ? $remaining : min($remaining, self::halfEven($costMinor - $residualMinor, $life));
    }

    /** Residual value from the class's residual percentage (basis points of cost). */
    public static function residual(int $costMinor, int $residualBp): int
    {
        return self::halfEven($costMinor * $residualBp, 10_000);
    }

    /** $numerator ÷ $denominator rounded half to even; both non-negative, denominator positive. */
    public static function halfEven(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $twice = 2 * ($numerator % $denominator);
        if ($twice > $denominator || ($twice === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }

        return $quotient;
    }
}
