<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Enums;

/** Design §5.4 policy states. */
enum PolicyStatus: string
{
    case Quote = 'quote';
    case Issued = 'issued';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Lapsed = 'lapsed';
    case Expired = 'expired';
    case Renewed = 'renewed';
}
