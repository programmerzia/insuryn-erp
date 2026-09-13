<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocationReversed;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Models\Policy;

/**
 * Commission is earned on receipt (design §4.5), so when a receipt's allocation is reversed (bounced cheque) every beneficiary's commission on
 * it is clawed back in full, dated the reversal, as a negative entry tied to the allocation, posting COMMISSION_CLAWBACK. Conditional entries on
 * the allocation were never posted, so they are reversed.
 */
final class ClawBackCommissionOnReversal
{
    public function __construct(private readonly CommissionAccountingEvents $accounting) {}

    public function handle(ReceiptAllocationReversed $event): void
    {
        CommissionEntry::query()->where('receipt_allocation_id', $event->receiptAllocationId)->where('status', 'conditional')->update(['status' => 'reversed']);
        $earned = CommissionEntry::query()->where('receipt_allocation_id', $event->receiptAllocationId)->where('kind', 'earned')->where('status', '<>', 'reversed')
            ->orderBy('created_at')->orderBy('id')->get();
        if ($earned->isEmpty()) {
            return;
        }
        $policy = Policy::query()->findOrFail($event->policyId);
        foreach ($earned as $entry) {
            $clawback = CommissionEntry::query()->create([
                'entity_id' => $entry->entity_id, 'branch_id' => $entry->branch_id, 'agent_id' => $entry->agent_id, 'policy_id' => $entry->policy_id,
                'receipt_allocation_id' => $entry->receipt_allocation_id, 'commission_plan_id' => $entry->commission_plan_id, 'scheme_id' => $entry->scheme_id,
                'rule_id' => $entry->rule_id, 'beneficiary_role' => $entry->beneficiary_role, 'level_code' => $entry->level_code, 'kind' => 'clawback',
                'base_minor' => -$entry->base_minor, 'rate_bp' => $entry->rate_bp, 'amount_minor' => -$entry->amount_minor, 'withholding_minor' => -$entry->withholding_minor,
                'currency' => $entry->currency, 'earned_on' => $event->reversedOn->toDateString(), 'status' => 'accrued',
            ]);
            $this->accounting->clawedBack($clawback, $policy);
        }
    }
}
