<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application\Reconciliation;

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.1 premium subledger → premium_receivable: per policy, premium billed (issue and endorsement deltas) less receivable credited on
 * cancellation less premium allocated (added back from the date an allocation is reversed), each counted from the date its accounting event posts on. Equals Σ(installment amount − paid −
 * cancelled) once every dated movement has happened.
 *
 * ASSUMPTION: A-8 — subledger balances are rebuilt as of the date from dated business rows (accounting_date, posted_on).
 */
final class PremiumReconciler implements SubledgerReconciler
{
    public function subledger(): string
    {
        return 'premium';
    }

    public function itemDimension(): string
    {
        return 'policy';
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $billed = DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')
            ->where('p.entity_id', $entityId)->where('t.accounting_date', '<=', $day)
            ->selectRaw("t.policy_id as object_id, case t.type when 'cancellation' then -coalesce((t.amounts->>'receivable_outstanding')::bigint, 0)
                when 'renewal' then 0 else t.premium_delta_minor end as amount");
        $allocated = DB::table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('r.entity_id', $entityId)->where('a.target_type', 'installment')->where('a.posted_on', '<=', $day)
            ->selectRaw('a.policy_id as object_id, -a.amount_minor as amount');
        $reversed = DB::table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('r.entity_id', $entityId)->where('a.target_type', 'installment')->where('a.reversed_on', '<=', $day)
            ->selectRaw('a.policy_id as object_id, a.amount_minor as amount');

        return SubledgerItems::of('policy', DB::query()->fromSub($billed->unionAll($allocated)->unionAll($reversed), 'm')
            ->groupBy('object_id')->orderBy('object_id')->selectRaw('object_id, sum(amount) as amount')->get());
    }
}
