<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Suspense ageing (design §4.9, spec §2): open suspense by days since the receipt's value date. */
final class SuspenseQuery
{
    /** Upper bound in days (inclusive) of each bucket; the last bucket is open-ended. */
    private const BUCKETS = ['0-30' => 30, '31-60' => 60, '61-90' => 90, '90+' => PHP_INT_MAX];

    /**
     * Items received on or before $asOf that are still open, oldest first.
     *
     * @return array{as_of: string, buckets: array<string, int>, total_minor: int,
     *     items: list<array{id: string, receipt_id: string, receipt_number: string, reference: string|null, branch_id: string, aged_since: string, open_minor: int, days: int}>}
     */
    public function ageing(?string $entityId, CarbonImmutable $asOf): array
    {
        $rows = DB::table('suspense_items as s')->join('receipts as r', 'r.id', '=', 's.receipt_id')
            ->where('s.status', 'open')->where('s.aged_since', '<=', $asOf->toDateString())
            ->when($entityId !== null, fn ($q) => $q->where('s.entity_id', $entityId))
            ->orderBy('s.aged_since')->orderBy('s.id')
            ->get(['s.id', 's.receipt_id', 'r.number', 'r.reference', 'r.branch_id', 's.aged_since', 's.amount_minor', 's.allocated_minor']);

        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $items = [];
        foreach ($rows as $row) {
            $days = (int) CarbonImmutable::parse((string) $row->aged_since)->diffInDays($asOf);
            $open = (int) $row->amount_minor - (int) $row->allocated_minor;
            $buckets[self::bucketFor($days)] += $open;
            $items[] = ['id' => (string) $row->id, 'receipt_id' => (string) $row->receipt_id, 'receipt_number' => (string) $row->number,
                'reference' => $row->reference === null ? null : (string) $row->reference, 'branch_id' => (string) $row->branch_id,
                'aged_since' => (string) $row->aged_since, 'open_minor' => $open, 'days' => $days];
        }

        return ['as_of' => $asOf->toDateString(), 'buckets' => $buckets, 'total_minor' => array_sum($buckets), 'items' => $items];
    }

    private static function bucketFor(int $days): string
    {
        foreach (self::BUCKETS as $bucket => $maxDays) {
            if ($days <= $maxDays) {
                return $bucket;
            }
        }

        return '90+';
    }
}
