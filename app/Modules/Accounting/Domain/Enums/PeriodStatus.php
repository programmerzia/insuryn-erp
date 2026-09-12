<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum PeriodStatus: string
{
    case Open = 'open';
    case SoftLocked = 'soft_locked';
    case Locked = 'locked';
}
