<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Events;

use App\Modules\Accounting\Domain\Enums\EventStatus;
use Illuminate\Support\Facades\DB;

/** Recently posted accounting events for the events screen (posted events leave the exception queue). */
final class RecentAccountingEventsQuery
{
    /** @return list<array{id: string, event_type: string, status: string, transaction_date: string, posted_at: string|null, source_label: string|null, journal_id: string|null, journal_number: string|null, via_api: bool}> */
    public function list(string $entityId, int $limit = 25): array
    {
        $rows = DB::table('accounting_events as e')
            ->leftJoin('external_event_intakes as i', 'i.accounting_event_id', '=', 'e.id')
            ->leftJoin('journal_batches as b', 'b.accounting_event_id', '=', 'e.id')
            ->leftJoin('journals as j', function ($join): void {
                $join->on('j.batch_id', '=', 'b.id')->where('j.status', 'posted');
            })
            ->where('e.entity_id', $entityId)
            ->where('e.status', EventStatus::Posted->value)
            ->orderByRaw('CASE WHEN i.id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc(DB::raw('COALESCE(j.posted_at, e.created_at)'))
            ->orderByDesc('e.id')
            ->get(['e.id', 'e.event_type', 'e.status', 'e.transaction_date', 'e.created_at', 'e.source_type', 'e.source_id',
                'i.id as intake_id', 'i.source_number as external_source_number', 'j.id as journal_id', 'j.number as journal_number', 'j.posted_at']);

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $viaApi = $row->intake_id !== null;
            $sourceLabel = $viaApi
                ? (string) ($row->external_source_number ?: 'External API')
                : ($row->source_type !== null ? (string) $row->source_type : null);

            $result[] = [
                'id' => $id,
                'event_type' => (string) $row->event_type,
                'status' => (string) $row->status,
                'transaction_date' => (string) $row->transaction_date,
                'posted_at' => $row->posted_at === null ? ($row->created_at === null ? null : (string) $row->created_at) : (string) $row->posted_at,
                'source_label' => $sourceLabel,
                'journal_id' => $row->journal_id === null ? null : (string) $row->journal_id,
                'journal_number' => $row->journal_number === null ? null : (string) $row->journal_number,
                'via_api' => $viaApi,
            ];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
