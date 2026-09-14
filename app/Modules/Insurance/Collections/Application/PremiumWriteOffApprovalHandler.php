<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\Queries\EventLinesPreview;
use App\Modules\Insurance\Collections\Domain\Models\PremiumWriteOff;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use App\Modules\Platform\Approvals\PreviewsApprovalSubject;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Support\Facades\DB;

/** Gap fixes W7 (GA-24): completes premium write-off approvals (object type `premium_write_off`) and shows the approver what the write-off posts. */
final class PremiumWriteOffApprovalHandler implements ApprovalHandler, DescribesApprovalSubject, PreviewsApprovalSubject
{
    public function __construct(private readonly PremiumWriteOffService $writeOffs, private readonly EventLinesPreview $lines) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->writeOffs->completeApproval($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->writeOffs->rejectApproval($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $row = DB::table('premium_write_offs as w')->join('policies as p', 'p.id', '=', 'w.policy_id')->where('w.id', $objectId)->first(['p.id', 'p.number', 'w.requested_minor', 'w.currency']);

        return ['title' => 'Write off the premium owed on '.($row->number ?? ''), 'amount_minor' => $row === null ? null : (int) $row->requested_minor,
            'currency' => $row === null ? null : (string) $row->currency, 'link' => $row === null ? null : "/policies/{$row->id}"];
    }

    public function preview(string $objectId): array
    {
        $writeOff = PremiumWriteOff::query()->find($objectId);
        $policy = $writeOff === null ? null : Policy::query()->find($writeOff->policy_id);
        if ($writeOff === null || $policy === null) {
            return ['link_label' => 'Open the policy', 'details' => [], 'lines' => [], 'posts_on_final_step' => true];
        }
        $today = app(BusinessClock::class)->today($policy->entity_id);
        $cancelled = DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'cancellation')->orderByDesc('created_at')->value('effective_date');

        return ['link_label' => 'Open the policy', 'details' => [
            ['label' => 'Policy', 'value' => (string) $policy->number],
            ...($cancelled === null ? [] : [['label' => 'Cancelled from', 'value' => (string) $cancelled, 'date' => true]]),
            ['label' => 'Written off on', 'value' => $today->toDateString(), 'date' => true],
            ['label' => 'Reason', 'value' => $writeOff->reason],
        ], 'lines' => $this->lines->lines($policy->entity_id, 'PREMIUM_WRITTEN_OFF', $today, $policy->currency, CollectionsAccountingEvents::writeOffPayload($writeOff),
            PolicyAccountingEvents::dimensions($policy)), 'posts_on_final_step' => true];
    }
}
