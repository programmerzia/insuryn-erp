<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Enums;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
