<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\ExternalEventValidator;
use App\Modules\Accounting\Application\Integration\LedgerEventQuery;
use App\Modules\Accounting\Application\Integration\SubmitExternalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** POST/GET /api/v1/events — external accounting event ingest (docs/plan/api-accounting-v1.md). */
final class EventController
{
    #[LedgerOperation('Submit an accounting event (queued by default; ?sync=1 posts immediately)', 'EventWrite', request: 'EventBody', status: 202, ability: 'integration:events:write', errors: [422])]
    public function store(Request $request, SubmitExternalEvent $submit, ExternalEventValidator $validator): JsonResponse
    {
        /** @var array{event_type: string, idempotency_key: string, transaction_date: string, effective_date?: string, currency: string, payload: array<string, mixed>, dimensions: array<string, mixed>, source: array{type: string, id: string, number?: string|null}} $data */
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:200'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'payload' => ['present', 'array'],
            'dimensions' => ['required', 'array'],
            'source' => ['required', 'array'],
            'source.type' => ['required', 'string', 'max:64'],
            'source.id' => ['required', 'string', 'max:128'],
            'source.number' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $entityId = (string) DB::table('legal_entities')->orderBy('code')->value('id');
        $transactionDate = CarbonImmutable::parse($data['transaction_date']);
        $effectiveDate = CarbonImmutable::parse($data['effective_date'] ?? $data['transaction_date']);
        $sync = filter_var($request->query('sync', '0'), FILTER_VALIDATE_BOOL);
        $currency = strtoupper($data['currency']);
        // Fail fast (422 with field and reason) on everything the rules are known to refuse: unknown type, missing or unknown dimensions,
        // payload amounts, a locked period, a foreign currency. Codes become ids here; the kernel gets what it stores.
        $validated = $validator->validate($entityId, $data['event_type'], $transactionDate, $effectiveDate, $currency, $data['payload'], $data['dimensions']);

        $result = $submit->submit(
            $entityId, $data['event_type'], $data['idempotency_key'], $transactionDate, $effectiveDate,
            $currency, $data['payload'], $validated->dimensions, $data['source'],
            $request->user()?->getAuthIdentifier(), $sync,
        );

        $event = $result->event->fresh() ?? $result->event;
        $body = [
            'intake_id' => $result->intakeId,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'status' => $event->status->value,
            'idempotency_key' => $event->idempotency_key,
            'created' => $result->created,
            'resubmitted' => $result->resubmitted,
        ];
        if ($event->failure_reason !== null && $event->status->value === 'failed') {
            $body['failure_reason'] = $event->failure_reason;
        }
        if ($result->journals !== []) {
            $body['journals'] = array_map(fn ($j): array => [
                'id' => $j->id, 'number' => $j->number, 'status' => $j->status->value, 'posting_date' => $j->posting_date->toDateString(),
            ], $result->journals);
        }

        if ($sync && $event->status->value === 'failed') {
            // The rules refused what the boundary could not foresee (an unmapped role, an unbalanced draft): the event is on record as failed
            // and a resend of the same idempotency_key with a corrected body replaces it.
            return response()->json(['message' => (string) $event->failure_reason, 'reason' => 'POSTING_FAILED', 'data' => $body], 422);
        }
        $status = $result->resubmitted ? 200 : ($sync && $result->journals !== [] ? 201 : ($result->created ? 202 : 200));

        return response()->json(['data' => $body], $status);
    }

    #[LedgerOperation('Event status and linked journals', 'EventDetail')]
    public function show(string $event, LedgerEventQuery $query): JsonResponse
    {
        $row = $query->find($event);
        if ($row === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        return response()->json(['data' => $row]);
    }
}
