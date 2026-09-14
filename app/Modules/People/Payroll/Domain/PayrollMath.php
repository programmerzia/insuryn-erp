<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Domain;

/** Integer payroll arithmetic (design §B.10.2 "no floats"): minor units, basis points, half-even division. */
final class PayrollMath
{
    /** $amount × $numerator / $denominator, rounded half to even. */
    public static function prorate(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive.');
        }

        return self::divide($amount * $numerator, $denominator);
    }

    public static function bp(int $amount, int $basisPoints): int
    {
        return self::prorate($amount, $basisPoints, 10_000);
    }

    /** Half-even integer division of a non-negative or negative dividend by a positive divisor. */
    public static function divide(int $dividend, int $divisor): int
    {
        $sign = $dividend < 0 ? -1 : 1;
        $abs = abs($dividend);
        $quotient = intdiv($abs, $divisor);
        $remainder = $abs % $divisor;
        $twice = 2 * $remainder;
        if ($twice > $divisor || ($twice === $divisor && $quotient % 2 === 1)) {
            $quotient++;
        }

        return $sign * $quotient;
    }

    /**
     * Tax on an annual taxable income from progressive bands (each band a width in minor units, null = everything above), rates in basis points.
     *
     * @param list<array{band_minor: int|null, rate_bp: int}> $slabs in order
     * @return array{tax: int, bands: list<array{from: int, to: int|null, rate_bp: int, taxed: int, tax: int}>}
     */
    public static function slabTax(int $taxable, array $slabs): array
    {
        $left = max(0, $taxable);
        $from = 0;
        $tax = 0;
        $bands = [];
        foreach ($slabs as $slab) {
            if ($left <= 0) {
                break;
            }
            $width = $slab['band_minor'];
            $taxed = $width === null ? $left : min($left, $width);
            $bandTax = self::bp($taxed, $slab['rate_bp']);
            $bands[] = ['from' => $from, 'to' => $width === null ? null : $from + $width, 'rate_bp' => $slab['rate_bp'], 'taxed' => $taxed, 'tax' => $bandTax];
            $tax += $bandTax;
            $left -= $taxed;
            $from += $taxed;
        }

        return ['tax' => $tax, 'bands' => $bands];
    }
}
