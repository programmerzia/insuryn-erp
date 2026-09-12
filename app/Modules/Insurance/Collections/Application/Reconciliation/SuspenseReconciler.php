<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application\Reconciliation;

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Design §6.1 suspense subledger → suspense_receipts: per receipt, suspense received on or before the date less suspense allocated by then. */
final class SuspenseReconciler implements SubledgerReconciler
{
    public function subledger(): string
    {
        return 'suspense';
    }

    public function itemDimension(): string
    {
        return 'receipt';
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $received = DB::table('suspense_items')->where('entity_id', $entityId)->where('aged_since', '<=', $day)
            ->selectRaw('receipt_id as object_id, amount_minor as amount');
        $allocated = DB::table('receipt_allocations as a')->join('suspense_items as s', 's.id', '=', 'a.suspense_item_id')
            ->where('s.entity_id', $entityId)->where('a.posted_on', '<=', $day)
            ->selectRaw('s.receipt_id as object_id, -a.amount_minor as amount');

        return SubledgerItems::of('receipt', DB::query()->fromSub($received->unionAll($allocated), 'm')
            ->groupBy('object_id')->orderBy('object_id')->selectRaw('object_id, sum(amount) as amount')->get());
    }
}
