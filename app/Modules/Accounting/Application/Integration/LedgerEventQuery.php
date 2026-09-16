<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use Illuminate\Support\Facades\DB;

/** Read model for GET /api/v1/events/{id}. */
final class LedgerEventQuery
{
    /** @return array<string, mixed>|null */
    public function find(string $eventId): ?array
    {
        $event = DB::table('accounting_events as e')
            ->leftJoin('external_event_intakes as i', 'i.accounting_event_id', '=', 'e.id')
            ->where('e.id', $eventId)
            ->first(['e.id', 'e.event_type', 'e.status', 'e.transaction_date', 'e.effective_date', 'e.currency', 'e.payload', 'e.dimensions',
                'e.failure_reason', 'e.idempotency_key', 'e.source_type', 'e.source_id', 'i.id as intake_id', 'i.source_type as external_source_type',
                'i.source_id as external_source_id', 'i.source_number as external_source_number']);
        if ($event === null) {
            return null;
        }

        $journals = DB::table('journal_batches as b')
            ->join('journals as j', 'j.batch_id', '=', 'b.id')
            ->where('b.accounting_event_id', $eventId)
            ->orderBy('j.number')
            ->get(['j.id', 'j.number', 'j.status', 'j.posting_date']);

        return [
            'id' => (string) $event->id,
            'intake_id' => $event->intake_id === null ? null : (string) $event->intake_id,
            'event_type' => (string) $event->event_type,
            'status' => (string) $event->status,
            'idempotency_key' => (string) $event->idempotency_key,
            'transaction_date' => (string) $event->transaction_date,
            'effective_date' => (string) $event->effective_date,
            'currency' => (string) $event->currency,
            'payload' => json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR),
            'dimensions' => json_decode((string) $event->dimensions, true, 512, JSON_THROW_ON_ERROR),
            'failure_reason' => $event->failure_reason === null ? null : (string) $event->failure_reason,
            'source' => $event->intake_id === null ? ['type' => (string) $event->source_type, 'id' => (string) $event->source_id] : [
                'type' => (string) $event->external_source_type,
                'id' => (string) $event->external_source_id,
                'number' => $event->external_source_number === null ? null : (string) $event->external_source_number,
            ],
            'journals' => $journals->map(fn (object $j): array => [
                'id' => (string) $j->id,
                'number' => $j->number === null ? null : (string) $j->number,
                'status' => (string) $j->status,
                'posting_date' => (string) $j->posting_date,
            ])->all(),
        ];
    }
}
