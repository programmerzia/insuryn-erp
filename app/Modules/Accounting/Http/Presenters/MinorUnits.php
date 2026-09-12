<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Presenters;

use Brick\Money\Currency;

/**
 * Displays minor-unit amounts ("123456" BDT → "1,234.56") with integer string arithmetic only: money never
 * passes through a float (CONTEXT.md non-negotiable #5).
 */
final class MinorUnits
{
    public static function format(int $minorUnits, string $currency): string
    {
        $scale = Currency::of($currency)->getDefaultFractionDigits();
        $digits = str_pad((string) abs($minorUnits), $scale + 1, '0', STR_PAD_LEFT);
        $whole = $scale > 0 ? substr($digits, 0, -$scale) : $digits;
        $fraction = $scale > 0 ? '.'.substr($digits, -$scale) : '';
        $grouped = ltrim(strrev(implode(',', str_split(strrev($whole), 3))), ',');

        return ($minorUnits < 0 ? '-' : '').$grouped.$fraction;
    }
}
