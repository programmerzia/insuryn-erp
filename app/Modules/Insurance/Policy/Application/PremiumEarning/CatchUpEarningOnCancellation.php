<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\PremiumEarning;

use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.4: before the unearned remainder is released, the premium earned up to the cancellation date must be
 * earned. The catch-up = earned to date − Σ ledger (negative when a month was earned in full but cover stopped inside
 * it), posted in the cancellation's period, in the cancellation's transaction. Afterwards the policy's unearned premium is zero.
 */
final class CatchUpEarningOnCancellation
{
    public function __construct(
        private readonly FiscalPeriodQuery $periods,
        private readonly PremiumEarningRun $earning,
    ) {}

    public function handle(PolicyCancelled $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        $transaction = PolicyTransaction::query()->findOrFail($event->policyTransactionId);
        $earnedToDate = (int) ($transaction->amounts['earned_to_date'] ?? 0);
        $catchUp = $earnedToDate - (int) DB::table('premium_earning_ledger')->where('policy_id', $policy->id)->sum('earned_minor');
        if ($catchUp === 0) {
            return;
        }
        $period = $this->periods->containing($policy->entity_id, $transaction->effective_date);
        if ($period === null || ! $period->isOpen()) {
            throw new BusinessRuleViolation('PERIOD_NOT_OPEN', 'The cancellation date must fall in an open period so earned premium can be caught up.');
        }
        $this->earning->earn($policy, $period, $catchUp, 'cancellation_catch_up', null, $transaction->effective_date);
    }
}
