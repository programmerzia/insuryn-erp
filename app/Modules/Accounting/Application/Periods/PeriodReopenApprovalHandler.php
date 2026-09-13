<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes period reopen approvals (object type `fiscal_period_reopen`, design §5.3). */
final class PeriodReopenApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
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

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $period = DB::table('fiscal_periods')->where('id', $objectId)->first(['year', 'period']);

        return ['title' => 'Reopen period '.($period === null ? '' : "{$period->year}-{$period->period}"), 'amount_minor' => null, 'currency' => null, 'link' => null];
    }
}
