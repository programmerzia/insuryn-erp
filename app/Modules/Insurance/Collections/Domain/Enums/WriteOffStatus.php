<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Enums;

/** Gap fixes W7 (GA-24): a premium write-off waits for approval, then is written off, rejected, or found settled (nothing left owing when approved). */
enum WriteOffStatus: string
{
    case PendingApproval = 'pending_approval';
    case WrittenOff = 'written_off';
    case Settled = 'settled';
    case Rejected = 'rejected';
}
