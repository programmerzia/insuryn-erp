<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes manual journal approvals (object type `journal`). */
final class ManualJournalApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ManualJournalService $journals) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->journals->postApproved($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->journals->cancel($objectId, $deciderId, $reason);
    }
}
