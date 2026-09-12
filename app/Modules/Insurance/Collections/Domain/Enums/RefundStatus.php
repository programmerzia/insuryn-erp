<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Released = 'released';
    case Rejected = 'rejected';
}
