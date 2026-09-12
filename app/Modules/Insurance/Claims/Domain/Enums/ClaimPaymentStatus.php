<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Enums;

enum ClaimPaymentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case ReleaseRequested = 'release_requested';
    case ReleasePendingApproval = 'release_pending_approval';
    case Paid = 'paid';
    case Rejected = 'rejected';

    /**
     * Statuses whose amount is committed against the reserve (approved or on its way to approval or payment).
     *
     * @return list<string>
     */
    public static function committed(): array
    {
        return [self::PendingApproval->value, self::Approved->value, self::ReleaseRequested->value, self::ReleasePendingApproval->value, self::Paid->value];
    }

    /**
     * Statuses whose amount has passed CLAIM_APPROVED (sits in claims_payable until paid).
     *
     * @return list<string>
     */
    public static function approved(): array
    {
        return [self::Approved->value, self::ReleaseRequested->value, self::ReleasePendingApproval->value, self::Paid->value];
    }

    /**
     * Statuses of a payment not yet settled (claims cannot close while any exists).
     *
     * @return list<string>
     */
    public static function unsettled(): array
    {
        return [self::PendingApproval->value, self::Approved->value, self::ReleaseRequested->value, self::ReleasePendingApproval->value];
    }
}
