<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\PremiumMath;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-42: the net premium inside money paid against a policy. Installments are equal slices of the gross premium (net + VAT + stamp
 * duty) of the transaction that billed them — the issue for installments 1..n, each premium increase (endorsement) for the installment added
 * after it — so an amount allocated to an installment holds net premium in that transaction's proportion, net ÷ gross, half-even. An amount
 * not allocated to an installment (a policy-level allocation) uses the policy's current net and gross premium.
 */
final class PremiumNetShare
{
    /** The net premium in $amountMinor allocated by the receipt allocation; the whole amount when the policy has no gross premium to divide by. */
    public function ofAllocation(string $receiptAllocationId, int $amountMinor): int
    {
        $allocation = DB::table('receipt_allocations')->where('id', $receiptAllocationId)->first(['policy_id', 'target_type', 'target_id']);
        if (! $allocation instanceof \stdClass || $allocation->policy_id === null) {
            return $amountMinor;
        }
        $policy = DB::table('policies')->where('id', $allocation->policy_id)->first(['id', 'installment_count', 'net_premium_minor', 'gross_premium_minor']);
        if (! $policy instanceof \stdClass) {
            return $amountMinor;
        }
        [$net, $gross] = [(int) $policy->net_premium_minor, (int) $policy->gross_premium_minor];
        if ($allocation->target_type === 'installment') {
            $billing = $this->billingTransaction((string) $policy->id, max(1, (int) $policy->installment_count), (string) $allocation->target_id);
            if ($billing !== null) {
                [$net, $gross] = $billing;
            }
        }

        return $gross <= 0 ? $amountMinor : PremiumMath::prorate($amountMinor, $net, $gross);
    }

    /** @return array{0: int, 1: int}|null net and gross premium of the transaction that billed the installment */
    private function billingTransaction(string $policyId, int $installmentCount, string $installmentId): ?array
    {
        $no = DB::table('installments')->where('id', $installmentId)->value('no');
        if ($no === null) {
            return null;
        }
        // Premium increases, oldest first: the first is the issue (installments 1..n), the k-th after it billed installment n + k.
        $index = (int) $no <= $installmentCount ? 0 : (int) $no - $installmentCount;
        $transaction = DB::table('policy_transactions')->where('policy_id', $policyId)->where('premium_delta_minor', '>', 0)
            ->orderBy('created_at')->orderBy('id')->offset($index)->first(['net_delta_minor', 'premium_delta_minor']);

        return $transaction instanceof \stdClass ? [(int) $transaction->net_delta_minor, (int) $transaction->premium_delta_minor] : null;
    }
}
