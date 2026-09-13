<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Policy\Application\PolicyYear;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;

/**
 * Distribution design note §2 trigger "POLICY_ISSUED (premium_written)": rules on written premium earn on the gross premium, rules on net premium
 * on the net premium, dated the issue transaction. Phase 1 plans pay on receipt only, so this needs a compensation scheme.
 */
final class EarnCommissionOnIssue
{
    public function __construct(
        private readonly CommissionAccrual $accrual,
        private readonly PolicyYear $policyYear,
    ) {}

    public function handle(PolicyIssued $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        if ($policy->agent_id === null) {
            return;
        }
        $transaction = PolicyTransaction::query()->findOrFail($event->policyTransactionId);
        $year = $this->policyYear->of($policy, $policy->inception);
        $on = $transaction->effective_date;
        $this->accrual->accrue($policy, 'premium_written', $policy->gross_premium_minor, $year, $on, ['policy_transaction_id' => $transaction->id]);
        $this->accrual->accrue($policy, 'net_premium', $policy->net_premium_minor, $year, $on, ['policy_transaction_id' => $transaction->id]);
    }
}
