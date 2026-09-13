<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Hierarchy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reads of the effective-dated producer hierarchy (Distribution design note §1, §2 step 3 "walk the hierarchy snapshot at transaction date"). */
final class HierarchyQuery
{
    /**
     * The producer and everyone above it on $on, nearest first. A producer outside the hierarchy that day is a chain of one without a level.
     *
     * @return list<HierarchyNode>
     */
    public function hierarchyAt(string $producerId, CarbonImmutable $on): array
    {
        $day = $on->toDateString();
        $rows = DB::select(
            "WITH RECURSIVE chain(producer_id, parent_id, level_code, depth) AS (
                SELECT p.id, h.parent_producer_id, h.level_code, 0
                FROM producers p LEFT JOIN producer_hierarchy h ON h.producer_id = p.id AND h.effective_from <= ?::date AND (h.effective_to IS NULL OR h.effective_to > ?::date)
                WHERE p.id = ?
                UNION ALL
                SELECT p.id, h.parent_producer_id, h.level_code, c.depth + 1
                FROM chain c JOIN producers p ON p.id = c.parent_id
                LEFT JOIN producer_hierarchy h ON h.producer_id = p.id AND h.effective_from <= ?::date AND (h.effective_to IS NULL OR h.effective_to > ?::date)
                WHERE c.depth < 100
             )
             SELECT c.producer_id, p.code, c.level_code, c.depth FROM chain c JOIN producers p ON p.id = c.producer_id ORDER BY c.depth",
            [$day, $day, $producerId, $day, $day],
        );

        return array_values(array_map(fn (\stdClass $r): HierarchyNode => new HierarchyNode((string) $r->producer_id, (string) $r->code,
            $r->level_code === null ? null : (string) $r->level_code, (int) $r->depth), $rows));
    }

    /** The row in force for the producer on $on, if any. */
    public function positionAt(string $producerId, CarbonImmutable $on): ?\stdClass
    {
        return DB::table('producer_hierarchy')->where('producer_id', $producerId)->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on->toDateString()))
            ->first(['id', 'parent_producer_id', 'level_code', 'effective_from', 'effective_to']);
    }

    /** @return list<\stdClass> every position the producer has held, oldest first */
    public function historyOf(string $producerId): array
    {
        return array_values(DB::table('producer_hierarchy')->where('producer_id', $producerId)->orderBy('effective_from')
            ->get(['id', 'parent_producer_id', 'level_code', 'effective_from', 'effective_to'])->all());
    }
}
