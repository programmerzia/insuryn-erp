<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Policy\Application\PolicyYear;
use App\Modules\Insurance\Policy\Application\PremiumNetShare;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.2 → §4.5: commission is earned on receipt. Each premium allocation is a `premium_received` trigger for the compensation engine
 * (Distribution design note §2), in the allocation's policy year (the installment's due date) and on the allocation date.
 * Gap audit GA-42 (D-70): the commission base is the net premium inside the allocation — VAT and stamp duty excluded, as agent commission and
 * IDRA's commission caps are on net premium — unless `erp.commission.premium_received_base` is `gross` (the cash allocated, the earlier base).
 * ASSUMPTION: A-181.
 */
final class EarnCommissionOnAllocation
{
    public function __construct(
        private readonly CommissionAccrual $accrual,
        private readonly PolicyYear $policyYear,
        private readonly PremiumNetShare $netShare,
    ) {}

    /** The base commission is earned on for money allocated to a policy: its net premium share (default) or the whole amount (`gross`). */
    public function base(string $receiptAllocationId, int $amountMinor): int
    {
        return config('erp.commission.premium_received_base', 'net_premium') === 'gross' ? $amountMinor : $this->netShare->ofAllocation($receiptAllocationId, $amountMinor);
    }

    public function handle(ReceiptAllocated $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        if ($policy->agent_id === null) {
            return;
        }
        $due = DB::table('receipt_allocations as a')->join('installments as i', 'i.id', '=', 'a.target_id')->where('a.id', $event->receiptAllocationId)->value('i.due_date');
        $premiumDate = $due === null ? $event->allocatedOn : CarbonImmutable::parse((string) $due);

        $this->accrual->accrue($policy, 'premium_received', $this->base($event->receiptAllocationId, $event->amountMinor), $this->policyYear->of($policy, $premiumDate), $event->allocatedOn,
            ['receipt_allocation_id' => $event->receiptAllocationId]);
    }
}
