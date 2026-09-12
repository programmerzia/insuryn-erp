<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Enums;

enum ClaimStatus: string
{
    case Registered = 'registered';
    case Reserved = 'reserved';
    case Approved = 'approved';
    case Paid = 'paid';
    case Closed = 'closed';
    case Rejected = 'rejected';
}
