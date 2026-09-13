<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocationReversed;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Models\Policy;

/**
 * Commission is earned on receipt (design §4.5), so when a receipt's allocation is reversed (bounced cheque) the commission earned on it is
 * clawed back in full, dated the reversal, as a negative entry tied to the allocation, posting COMMISSION_CLAWBACK.
 */
final class ClawBackCommissionOnReversal
{
    public function __construct(private readonly CommissionAccountingEvents $accounting) {}

    public function handle(ReceiptAllocationReversed $event): void
    {
        $earned = CommissionEntry::query()->where('receipt_allocation_id', $event->receiptAllocationId)->where('kind', 'earned')->first();
        if ($earned === null) {
            return;
        }
        $clawback = CommissionEntry::query()->create([
            'entity_id' => $earned->entity_id, 'branch_id' => $earned->branch_id, 'agent_id' => $earned->agent_id, 'policy_id' => $earned->policy_id,
            'receipt_allocation_id' => $earned->receipt_allocation_id, 'commission_plan_id' => $earned->commission_plan_id, 'kind' => 'clawback',
            'base_minor' => -$earned->base_minor, 'rate_bp' => $earned->rate_bp, 'amount_minor' => -$earned->amount_minor, 'withholding_minor' => -$earned->withholding_minor,
            'currency' => $earned->currency, 'earned_on' => $event->reversedOn->toDateString(), 'status' => 'accrued',
        ]);
        $this->accounting->clawedBack($clawback, Policy::query()->findOrFail($event->policyId));
    }
}
