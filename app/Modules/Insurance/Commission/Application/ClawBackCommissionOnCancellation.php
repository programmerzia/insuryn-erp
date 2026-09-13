<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Distribution\Application\Compensation\ClawbackCalculator;
use App\Modules\Distribution\Application\Compensation\CommissionShare;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.4 event C and Distribution design note §2 step 5: on cancellation every beneficiary (seller and overriding managers) gives back
 * commission on the unearned share — its commission still standing on the policy × unearned remaining / net premium (half-even), withholding
 * likewise — as one negative entry each, posted COMMISSION_CLAWBACK on the cancellation date in the cancellation's transaction. Conditional
 * entries were never posted or paid, so they are reversed instead. Earned entries keep their status; clawbacks net on the next statement (§5.6).
 */
final class ClawBackCommissionOnCancellation
{
    public function __construct(
        private readonly CommissionAccountingEvents $accounting,
        private readonly ClawbackCalculator $clawbacks,
    ) {}

    public function handle(PolicyCancelled $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        CommissionEntry::query()->where('policy_id', $policy->id)->where('status', 'conditional')->update(['status' => 'reversed']);
        if ($event->netPremiumMinor <= 0 || $event->unearnedRemainingMinor <= 0) {
            return;
        }
        // Commission still standing per beneficiary: earned entries less clawbacks of reversed allocations (bounced cheques).
        $standing = array_values(DB::table('commission_entries')->where('policy_id', $policy->id)->where('status', '<>', 'reversed')
            ->where(fn ($q) => $q->where('kind', 'earned')->orWhere(fn ($r) => $r->where('kind', 'clawback')->whereNotNull('receipt_allocation_id')))
            ->selectRaw('agent_id, sum(base_minor) as base, sum(amount_minor) as amount, sum(withholding_minor) as withholding')->groupBy('agent_id')->orderBy('agent_id')->get()
            ->map(fn (\stdClass $row): CommissionShare => new CommissionShare((string) $row->agent_id, (int) $row->base, (int) $row->amount, (int) $row->withholding))->all());
        $transaction = PolicyTransaction::query()->findOrFail($event->policyTransactionId);

        foreach ($this->clawbacks->onCancellation($standing, $event->unearnedRemainingMinor, $event->netPremiumMinor) as $share) {
            $entry = CommissionEntry::query()->create([
                'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'agent_id' => $share->producerId, 'policy_id' => $policy->id,
                'policy_transaction_id' => $transaction->id, 'kind' => 'clawback', 'base_minor' => $share->baseMinor, 'rate_bp' => null,
                'amount_minor' => $share->amountMinor, 'withholding_minor' => $share->withholdingMinor, 'currency' => $policy->currency,
                'earned_on' => $transaction->effective_date->toDateString(), 'status' => 'accrued',
            ]);
            $this->accounting->clawedBack($entry, $policy);
        }
    }
}
