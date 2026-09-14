<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Reconciliation;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-43: what policies owe the government — VAT and levies (`premium_tax_payable`) or stamp duty (`stamp_duty_payable`, D-37) — per policy, from
 * the policy transactions as they post (A-8): issue and endorsement deltas on their accounting date, less the VAT a cancellation gives back.
 *
 * ASSUMPTION A-204: the payment to the government has no module yet (AP is slice 2.3); it is a manual journal debiting the payable, without a policy.
 * So the register is compared with the lines that carry a policy, and lines without one (payments, or a manual correction) are outside it. A policy
 * posting that failed or carries a different amount is the variance.
 */
abstract class PolicyDutyReconciler implements AccountBalanceReconciler
{
    public function itemDimension(): string
    {
        return 'policy';
    }

    public function excludesUnattributedLines(): bool
    {
        return true;
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        $rows = DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')
            ->where('p.entity_id', $entityId)->where('t.accounting_date', '<=', $asOf->toDateString())
            ->groupBy('t.policy_id')->orderBy('t.policy_id')
            ->selectRaw('t.policy_id as object_id, sum('.$this->amountExpression().') as amount')->get();

        return SubledgerItems::of('policy', $rows);
    }

    /** @return literal-string SQL over policy_transactions `t`: the duty each transaction adds (negative when it gives some back) */
    abstract protected function amountExpression(): string;
}
