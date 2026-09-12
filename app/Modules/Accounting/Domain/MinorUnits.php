<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Platform\Money\MinorUnits as PlatformMinorUnits;

/** The kernel's name for {@see PlatformMinorUnits}; kept so accounting code reads as before. */
final class MinorUnits
{
    /** 123456 BDT → "1,234.56". */
    public static function format(int $minorUnits, string $currency): string
    {
        return PlatformMinorUnits::format($minorUnits, $currency);
    }

    /** "1,234.56" BDT → 123456; null when the text is not a non-negative amount with at most the currency's decimals. */
    public static function fromMajor(string $text, string $currency): ?int
    {
        return PlatformMinorUnits::fromMajor($text, $currency);
    }
}
