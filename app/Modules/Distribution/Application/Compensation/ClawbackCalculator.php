<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use App\Modules\Distribution\Domain\Compensation\ClawbackSplit;
use App\Modules\Distribution\Domain\Compensation\EarnedShare;

/** Design note §2 step 5: clawback on cancellation proportional to unearned, per beneficiary (golden fixture 06_clawback_split). */
final class ClawbackCalculator
{
    /**
     * @param list<CommissionShare> $earned
     * @return list<CommissionShare>
     */
    public function onCancellation(array $earned, int $unearnedRemainingMinor, int $netPremiumMinor): array
    {
        $shares = array_map(fn (CommissionShare $s): EarnedShare => new EarnedShare($s->producerId, $s->baseMinor, $s->amountMinor, $s->withholdingMinor), $earned);

        return array_map(fn (EarnedShare $c): CommissionShare => new CommissionShare($c->beneficiaryId, $c->baseMinor, $c->amountMinor, $c->withholdingMinor),
            ClawbackSplit::split($shares, $unearnedRemainingMinor, $netPremiumMinor));
    }
}
