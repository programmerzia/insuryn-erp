<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use Brick\Money\Currency;

/**
 * Money as integer minor units, converted to and from text with string arithmetic only: an amount never
 * passes through a float (CONTEXT.md non-negotiable #5). Scale = the currency's default fraction digits.
 */
final class MinorUnits
{
    /** 123456 BDT → "1,234.56". */
    public static function format(int $minorUnits, string $currency): string
    {
        $scale = Currency::of($currency)->getDefaultFractionDigits();
        $digits = str_pad((string) abs($minorUnits), $scale + 1, '0', STR_PAD_LEFT);
        $whole = $scale > 0 ? substr($digits, 0, -$scale) : $digits;
        $fraction = $scale > 0 ? '.'.substr($digits, -$scale) : '';
        $grouped = ltrim(strrev(implode(',', str_split(strrev($whole), 3))), ',');

        return ($minorUnits < 0 ? '-' : '').$grouped.$fraction;
    }

    /** "1,234.56" BDT → 123456; null when the text is not a non-negative amount with at most the currency's decimals. */
    public static function fromMajor(string $text, string $currency): ?int
    {
        $scale = Currency::of($currency)->getDefaultFractionDigits();
        $pattern = $scale > 0 ? '/^(\d{1,15})(?:\.(\d{1,'.$scale.'}))?$/' : '/^(\d{1,15})$/';
        if (preg_match($pattern, str_replace(',', '', $text), $parts) !== 1) {
            return null;
        }

        return (int) ltrim($parts[1].str_pad($parts[2] ?? '', $scale, '0'), '0');
    }
}
