<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Platform\Tax\TaxRates;

/**
 * Design §4.2 → §4.5: commission is earned on receipt. For each premium allocation to an agent's policy under a plan, one accrued
 * entry on the amount received (base), rate and withholding from the plan, posted as COMMISSION_EARNED in the allocation's transaction.
 */
final class EarnCommissionOnAllocation
{
    private const WHOLE_BP = 10_000;

    public function __construct(
        private readonly CommissionPlanResolver $plans,
        private readonly TaxRates $taxRates,
        private readonly CommissionAccountingEvents $accounting,
    ) {}

    public function handle(ReceiptAllocated $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        $plan = $this->plans->planFor($policy);
        if ($plan === null || $policy->agent_id === null) {
            return;
        }
        $amount = PremiumMath::prorate($event->amountMinor, $plan->rate_bp, self::WHOLE_BP);
        if ($amount === 0) {
            return;
        }
        $withholdingBp = $plan->withholding_tax_type === null ? 0
            : $this->taxRates->withholdingRateOn((string) $plan->withholding_jurisdiction, $plan->withholding_tax_type, $event->allocatedOn);

        $entry = CommissionEntry::query()->create([
            'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'agent_id' => $policy->agent_id, 'policy_id' => $policy->id,
            'receipt_allocation_id' => $event->receiptAllocationId, 'commission_plan_id' => $plan->id, 'kind' => 'earned',
            'base_minor' => $event->amountMinor, 'rate_bp' => $plan->rate_bp, 'amount_minor' => $amount,
            'withholding_minor' => PremiumMath::prorate($amount, $withholdingBp, self::WHOLE_BP), 'currency' => $policy->currency,
            'earned_on' => $event->allocatedOn->toDateString(), 'status' => 'accrued',
        ]);
        $this->accounting->earned($entry, $policy, $withholdingBp);
    }
}
