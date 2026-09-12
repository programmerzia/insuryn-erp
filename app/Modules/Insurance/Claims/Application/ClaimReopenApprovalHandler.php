<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes a claim reopen approval (object type `claim_reopen`); a rejection leaves the claim closed. */
final class ClaimReopenApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ClaimService $claims) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->claims->completeReopen($objectId, (string) ($context['reason'] ?? ''), $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void {}
}
