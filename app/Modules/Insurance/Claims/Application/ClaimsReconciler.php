<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.1 claims subledger → claims_outstanding + claims_payable (both roles of subledger `claims`): per claim, open reserve (reserve −
 * approved) plus approved-unpaid, i.e. Σ reserve history deltas recorded on or before the date − Σ payments paid on or before it.
 */
final class ClaimsReconciler implements SubledgerReconciler
{
    public function subledger(): string
    {
        return 'claims';
    }

    public function itemDimension(): string
    {
        return 'claim';
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $reserved = DB::table('claim_reserves as r')->join('claims as c', 'c.id', '=', 'r.claim_id')->where('c.entity_id', $entityId)->where('r.recorded_on', '<=', $day)
            ->selectRaw('r.claim_id as object_id, r.delta_minor as amount');
        $paid = DB::table('claim_payments as p')->join('claims as c', 'c.id', '=', 'p.claim_id')->where('c.entity_id', $entityId)
            ->where('p.status', 'paid')->where('p.paid_on', '<=', $day)
            ->selectRaw('p.claim_id as object_id, -p.amount_minor as amount');

        return SubledgerItems::of('claim', DB::query()->fromSub($reserved->unionAll($paid), 'm')
            ->groupBy('object_id')->orderBy('object_id')->selectRaw('object_id, sum(amount) as amount')->get());
    }
}
