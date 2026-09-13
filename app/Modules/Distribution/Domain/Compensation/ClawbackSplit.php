<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/**
 * Design note §2 step 5 "clawback on cancellation proportional to unearned, per beneficiary": each beneficiary gives back its earned base,
 * commission and withholding × unearned remaining / net premium, rounded half-even, as negative shares. Beneficiaries with nothing to give back are left out.
 */
final class ClawbackSplit
{
    /**
     * @param list<EarnedShare> $earned
     * @return list<EarnedShare>
     */
    public static function split(array $earned, int $unearnedRemainingMinor, int $netPremiumMinor): array
    {
        if ($netPremiumMinor <= 0 || $unearnedRemainingMinor <= 0) {
            return [];
        }
        $clawbacks = [];
        foreach ($earned as $share) {
            $amount = HalfEven::prorate($share->amountMinor, $unearnedRemainingMinor, $netPremiumMinor);
            if ($amount === 0) {
                continue;
            }
            $clawbacks[] = new EarnedShare($share->beneficiaryId, -HalfEven::prorate($share->baseMinor, $unearnedRemainingMinor, $netPremiumMinor), -$amount,
                -HalfEven::prorate($share->withholdingMinor, $unearnedRemainingMinor, $netPremiumMinor));
        }

        return $clawbacks;
    }
}
