<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Enums;

/** Distribution design note §1 compensation_rules.basis: which premium a rate applies to (§2 trigger: received → allocation, written → issue). */
enum CommissionBasis: string
{
    case PremiumReceived = 'premium_received';
    case PremiumWritten = 'premium_written';
    case NetPremium = 'net_premium';
}
