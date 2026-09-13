<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Domain\Enums;

/** Know-your-customer check on a proposal (slice R5): pending until an officer records the verified identity document, or an underwriter waives it with a reason. */
enum KycStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Waived = 'waived';
}
