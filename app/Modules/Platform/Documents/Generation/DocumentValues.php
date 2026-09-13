<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;

/**
 * Formatting shared by document data providers (UX brief §1.7): amounts with thousands separators and negatives in parentheses, the currency
 * named once per table; dates like "14 Sep 2026". ASSUMPTION: A-103 — Bangla documents keep Latin digits and English month abbreviations for
 * numbers and dates (the brief makes Bengali digits optional); labels and fixed text are in Bangla.
 */
final class DocumentValues
{
    public static function money(int $minor, string $currency): string
    {
        $text = MinorUnits::format(abs($minor), $currency);

        return $minor < 0 ? "({$text})" : $text;
    }

    public static function date(CarbonImmutable|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return ($date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date))->format('j M Y');
    }

    public static function label(string $locale, string $english, string $bangla): string
    {
        return $locale === 'bn' ? $bangla : $english;
    }
}
