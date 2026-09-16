<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\Queries\JournalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** POST /api/v1/events/{id}/replay — re-run rules against a posted event and diff with the journal. */
final class ReplayEventQuery
{
    public function __construct(
        private readonly PreviewExternalEvent $preview,
        private readonly JournalQuery $journals,
    ) {}

    /** @return array<string, mixed> */
    public function replay(string $eventId): array
    {
        $event = DB::table('accounting_events')->where('id', $eventId)->first(['id', 'entity_id', 'event_type', 'effective_date', 'currency', 'payload', 'dimensions', 'status']);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        $dimensions = json_decode((string) $event->dimensions, true, 512, JSON_THROW_ON_ERROR);
        $preview = $this->preview->preview(
            (string) $event->entity_id,
            (string) $event->event_type,
            CarbonImmutable::parse((string) $event->effective_date),
            (string) $event->currency,
            $payload,
            $dimensions,
        );

        $journalId = DB::table('journal_batches as b')->join('journals as j', 'j.batch_id', '=', 'b.id')
            ->where('b.accounting_event_id', $eventId)->where('j.status', 'posted')->orderBy('j.number')->value('j.id');
        $posted = $journalId === null ? [] : ($this->journals->detail((string) $journalId)['lines'] ?? []);
        $postedTuples = array_map(fn (array $l): array => [$l['role_code'], $l['side'], $l['amount_minor']], $posted);
        $previewTuples = array_map(fn (array $l): array => [$l['role_code'], $l['side'], $l['amount_minor']], $preview['lines']);
        sort($postedTuples);
        sort($previewTuples);

        return [
            'event_id' => (string) $event->id,
            'status' => (string) $event->status,
            'matches' => $postedTuples === $previewTuples,
            'preview' => $preview,
            'posted_journal_id' => $journalId === null ? null : (string) $journalId,
            'posted_lines' => $posted,
        ];
    }
}
