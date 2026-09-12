<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum EventStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Posting = 'posting';
    case Posted = 'posted';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
