<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use App\Modules\Platform\Approvals\PreviewsApprovalSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Completes period reopen approvals (object type `fiscal_period_reopen`, design §5.3). */
final class PeriodReopenApprovalHandler implements ApprovalHandler, DescribesApprovalSubject, PreviewsApprovalSubject
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

    /**
     * Gap fixes W7 (GA-04 remainder): what reopening does before it is approved — the month, its status now, the reason, how much is posted in it and whether
     * its close was signed off. Reopening posts no journal (no lines); it lets journals post into the month again and the close has to run again.
     */
    public function preview(string $objectId): array
    {
        $period = DB::table('fiscal_periods')->where('id', $objectId)->first(['id', 'starts', 'ends', 'status']);
        if ($period === null) {
            return ['link_label' => 'Open the month-end close', 'details' => [], 'lines' => [], 'posts_on_final_step' => false];
        }
        $context = DB::table('approvals')->where('object_type', 'fiscal_period_reopen')->where('object_id', $objectId)->where('status', 'pending')->value('context');
        $reason = is_string($context) ? (string) (json_decode($context, true)['reason'] ?? '') : '';
        $journals = DB::table('journals')->where('period_id', $objectId)->where('status', 'posted')->count();
        $signedOff = DB::table('period_close_runs')->where('period_id', $objectId)->where('status', 'completed')->exists();

        return ['link_label' => 'Open the month-end close', 'details' => [
            ['label' => 'Month', 'value' => CarbonImmutable::parse((string) $period->starts)->format('F Y')],
            ['label' => 'Status now', 'value' => ucfirst(str_replace('_', ' ', (string) $period->status))],
            ['label' => 'Posted in it', 'value' => $journals === 1 ? '1 journal' : "{$journals} journals"],
            ['label' => 'Close', 'value' => $signedOff ? 'Signed off; it must be run again after reopening' : 'Not signed off'],
            ...($reason === '' ? [] : [['label' => 'Reason', 'value' => $reason]]),
            ['label' => 'Posts', 'value' => 'Nothing: reopening lets journals post into this month again'],
        ], 'lines' => [], 'posts_on_final_step' => false];
    }
}
