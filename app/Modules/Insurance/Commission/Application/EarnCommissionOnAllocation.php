<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Policy\Application\PolicyYear;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.2 → §4.5: commission is earned on receipt. Each premium allocation is a `premium_received` trigger for the compensation engine
 * (Distribution design note §2), in the allocation's policy year (the installment's due date) and on the allocation date.
 */
final class EarnCommissionOnAllocation
{
    public function __construct(
        private readonly CommissionAccrual $accrual,
        private readonly PolicyYear $policyYear,
    ) {}

    public function handle(ReceiptAllocated $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        if ($policy->agent_id === null) {
            return;
        }
        $due = DB::table('receipt_allocations as a')->join('installments as i', 'i.id', '=', 'a.target_id')->where('a.id', $event->receiptAllocationId)->value('i.due_date');
        $premiumDate = $due === null ? $event->allocatedOn : CarbonImmutable::parse((string) $due);

        $this->accrual->accrue($policy, 'premium_received', $event->amountMinor, $this->policyYear->of($policy, $premiumDate), $event->allocatedOn,
            ['receipt_allocation_id' => $event->receiptAllocationId]);
    }
}
