<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reversals;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes reversal request approvals (object type `journal_reversal`). */
final class ReversalApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ReversalRequestService $requests) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->requests->execute($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->requests->markRejected($objectId, $deciderId, $reason);
    }
}
