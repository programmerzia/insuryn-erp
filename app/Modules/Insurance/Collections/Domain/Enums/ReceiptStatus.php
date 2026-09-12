<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Enums;

enum ReceiptStatus: string
{
    case Unallocated = 'unallocated';
    case PartiallyAllocated = 'partially_allocated';
    case Allocated = 'allocated';
    case Bounced = 'bounced';
    case Refunded = 'refunded';
}
