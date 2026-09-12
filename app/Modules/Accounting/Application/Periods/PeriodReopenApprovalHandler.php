<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Platform\Approvals\ApprovalHandler;

/** Completes period reopen approvals (object type `fiscal_period_reopen`, design §5.3). */
final class PeriodReopenApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly FiscalPeriodService $periods) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->periods->completeApprovedReopen($objectId, (string) ($context['requested_by'] ?? $finalApproverId), (string) ($context['reason'] ?? ''), $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        // The period stays as it was; the rejection is recorded on the approval and its audit trail.
    }
}
