<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Enums;

/** Addendum v2 §B.4 payment run: draft → pending_approval → approved → released; cancelled before release (items freed). */
enum PaymentRunStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
