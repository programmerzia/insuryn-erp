<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes a claim payment approval (object type `claim_payment`). */
final class ClaimPaymentApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ClaimPaymentService $payments) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->payments->completeApproval($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->payments->rejectApproval($objectId, $deciderId, $reason);
    }
}
