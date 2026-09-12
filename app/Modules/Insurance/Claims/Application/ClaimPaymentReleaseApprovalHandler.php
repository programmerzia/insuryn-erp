<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes a claim payment release approval (object type `claim_payment_release`). */
final class ClaimPaymentReleaseApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ClaimPaymentService $payments) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->payments->completeRelease($objectId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->payments->rejectRelease($objectId, $deciderId, $reason);
    }
}
