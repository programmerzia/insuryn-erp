<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum JournalKind: string
{
    case System = 'system';
    case Manual = 'manual';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case Opening = 'opening';
}
