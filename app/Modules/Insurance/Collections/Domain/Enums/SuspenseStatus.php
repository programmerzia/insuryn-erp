<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Enums;

enum SuspenseStatus: string
{
    case Open = 'open';
    case Allocated = 'allocated';
    case Refunded = 'refunded';
    case WrittenOff = 'written_off';
}
