<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Insurance\Policy\Domain\PremiumMath;

/**
 * Design §4.4 event C: on cancellation the agent gives back commission on the unearned share — Σ commission earned on the policy
 * × unearned remaining / net premium (half-even), withholding likewise — as one negative entry posted COMMISSION_CLAWBACK on the
 * cancellation date, in the cancellation's transaction. Earned entries keep their status; the clawback nets on the next statement (§5.6).
 */
final class ClawBackCommissionOnCancellation
{
    public function __construct(private readonly CommissionAccountingEvents $accounting) {}

    public function handle(PolicyCancelled $event): void
    {
        if ($event->netPremiumMinor <= 0 || $event->unearnedRemainingMinor <= 0) {
            return;
        }
        $policy = Policy::query()->findOrFail($event->policyId);
        $earned = CommissionEntry::query()->where('policy_id', $policy->id)->where('kind', 'earned')
            ->selectRaw('agent_id, sum(base_minor) as base, sum(amount_minor) as amount, sum(withholding_minor) as withholding')->groupBy('agent_id')->get();
        $transaction = PolicyTransaction::query()->findOrFail($event->policyTransactionId);

        foreach ($earned as $row) {
            $amount = PremiumMath::prorate((int) $row->getAttribute('amount'), $event->unearnedRemainingMinor, $event->netPremiumMinor);
            if ($amount === 0) {
                continue;
            }
            $entry = CommissionEntry::query()->create([
                'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'agent_id' => (string) $row->getAttribute('agent_id'), 'policy_id' => $policy->id,
                'policy_transaction_id' => $transaction->id, 'kind' => 'clawback',
                'base_minor' => -PremiumMath::prorate((int) $row->getAttribute('base'), $event->unearnedRemainingMinor, $event->netPremiumMinor),
                'rate_bp' => null, 'amount_minor' => -$amount,
                'withholding_minor' => -PremiumMath::prorate((int) $row->getAttribute('withholding'), $event->unearnedRemainingMinor, $event->netPremiumMinor),
                'currency' => $policy->currency, 'earned_on' => $transaction->effective_date->toDateString(), 'status' => 'accrued',
            ]);
            $this->accounting->clawedBack($entry, $policy);
        }
    }
}
