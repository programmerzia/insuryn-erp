<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain;

/**
 * The taxes on one supplier bill line (addendum v2 §B.4), in minor units without floats: VAT on the net amount, VAT deducted at source (never more
 * than the VAT) and income tax deducted at source on the net amount, each half-even in basis points (ASSUMPTION A-243 rates per supplier category).
 */
final readonly class Withholding
{
    private function __construct(public int $vatMinor, public int $vdsMinor, public int $tdsMinor) {}

    /** @param int|null $vatMinor the VAT on the supplier's invoice when given; otherwise the category rate */
    public static function forLine(int $netMinor, ?int $vatMinor, int $vatBp, int $vdsBp, int $tdsBp): self
    {
        $vat = $vatMinor ?? self::pct($netMinor, $vatBp);

        return new self($vat, min($vat, self::pct($netMinor, $vdsBp)), self::pct($netMinor, $tdsBp));
    }

    /** $base × $basisPoints / 10,000, rounded half to even. */
    public static function pct(int $base, int $basisPoints): int
    {
        $product = $base * $basisPoints;
        $quotient = intdiv($product, 10_000);
        $twice = 2 * abs($product % 10_000);
        if ($twice > 10_000 || ($twice === 10_000 && $quotient % 2 !== 0)) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
