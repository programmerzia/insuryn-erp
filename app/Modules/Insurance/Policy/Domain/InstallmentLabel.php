<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain;

/**
 * GA-25: how an installment is named wherever money is taken or allocated — `POL-HO-2026-000001 #2` for the original premium, `POL-HO-2026-000001/E1` for the
 * installment an endorsement's increase added (the endorsement number of A-104).
 */
final class InstallmentLabel
{
    public static function of(?string $policyNumber, int $no, ?int $endorsementNo): string
    {
        $number = $policyNumber ?? 'Quote';

        return $endorsementNo === null ? "{$number} #{$no}" : "{$number}/E{$endorsementNo}";
    }

    /** The short form on the policy's own installment list: `2` or `E1`. */
    public static function short(int $no, ?int $endorsementNo): string
    {
        return $endorsementNo === null ? (string) $no : "E{$endorsementNo}";
    }
}
