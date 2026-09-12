<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * Design §5.1. D-03 (accepted): SubmitAccountingEvent inserts events directly as `queued`. `received`
 * stays for a future pre-validation stage (received → validate → queued) and is not used yet.
 */
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
