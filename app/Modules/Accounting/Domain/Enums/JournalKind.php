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
    /** Gap fix GA-15 (D-81): the year-end close of income and expense into retained earnings. */
    case Closing = 'closing';
}
