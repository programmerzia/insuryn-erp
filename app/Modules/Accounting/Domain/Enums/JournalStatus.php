<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum JournalStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Queued = 'queued';
    case Posting = 'posting';
    case Posted = 'posted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
}
