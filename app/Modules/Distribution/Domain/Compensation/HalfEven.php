<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** Integer proration with half-even rounding on minor units (CONTEXT.md #5: never floats), the same rounding as the posting rules' pct(). */
final class HalfEven
{
    public static function prorate(int $amount, int $numerator, int $denominator): int
    {
        $product = abs($amount) * $numerator;
        $quotient = intdiv($product, $denominator);
        $remainder = $product - $quotient * $denominator;
        if ($remainder * 2 > $denominator || ($remainder * 2 === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }

        return $amount < 0 ? -$quotient : $quotient;
    }
}
