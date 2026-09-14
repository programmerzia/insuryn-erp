<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Enums;

/** Addendum v2 §B.4 bill state machine: draft → pending_approval → posted → partially_paid → paid; cancelled from draft or an unpaid posted bill. */
enum BillStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** @return list<string> statuses whose payable is owed to the supplier */
    public static function open(): array
    {
        return [self::Posted->value, self::PartiallyPaid->value];
    }
}
