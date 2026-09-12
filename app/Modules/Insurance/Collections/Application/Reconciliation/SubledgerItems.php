<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application\Reconciliation;

use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/** Shared plumbing for the insurance subledger reconcilers: summing item rows and pricing them in the entity's base currency. */
final class SubledgerItems
{
    /**
     * @param iterable<object> $rows each with object_id and amount
     * @return list<array{object_type: string, object_id: string, amount_minor: int}>
     */
    public static function of(string $objectType, iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $amount = (int) ($row->amount ?? 0);
            if ($amount !== 0) {
                $items[] = ['object_type' => $objectType, 'object_id' => (string) ($row->object_id ?? ''), 'amount_minor' => $amount];
            }
        }

        return $items;
    }

    /** @param list<array{object_type: string, object_id: string, amount_minor: int}> $items */
    public static function total(string $entityId, array $items): Money
    {
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');

        return Money::ofMinor(array_sum(array_column($items, 'amount_minor')), $currency);
    }
}
