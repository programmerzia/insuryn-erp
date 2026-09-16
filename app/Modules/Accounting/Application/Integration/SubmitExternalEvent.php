<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ledger API write path (docs/plan/api-accounting-v1.md): one intake row and one accounting event commit together,
 * then posting runs on the queue or synchronously when the caller asks for it.
 */
final class SubmitExternalEvent
{
    public function __construct(
        private readonly SubmitAccountingEvent $submit,
        private readonly PostingEngine $engine,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     * @param array{type: string, id: string, number?: string|null} $source
     */
    public function submit(
        string $entityId,
        string $eventType,
        string $idempotencyKey,
        CarbonImmutable $transactionDate,
        CarbonImmutable $effectiveDate,
        string $currency,
        array $payload,
        array $dimensions,
        array $source,
        ?string $submittedBy,
        bool $syncPost = false,
    ): ExternalEventResult {
        $result = DB::transaction(function () use ($entityId, $eventType, $idempotencyKey, $transactionDate, $effectiveDate, $currency, $payload, $dimensions, $source, $submittedBy): ExternalEventResult {
            $tenantId = TenantContext::id();
            $intakeId = (string) Str::uuid7();
            $inserted = DB::table('external_event_intakes')->insertOrIgnore([
                'id' => $intakeId, 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'idempotency_key' => $idempotencyKey,
                'event_type' => $eventType, 'transaction_date' => $transactionDate->toDateString(), 'effective_date' => $effectiveDate->toDateString(),
                'currency' => $currency, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                'source_type' => $source['type'], 'source_id' => $source['id'], 'source_number' => $source['number'] ?? null,
                'submitted_by' => $submittedBy, 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                $existing = DB::table('external_event_intakes')->where('idempotency_key', $idempotencyKey)->first(['id', 'accounting_event_id']);
                $event = AccountingEvent::query()->findOrFail((string) ($existing->accounting_event_id ?? throw new \RuntimeException('Duplicate intake without event.')));

                return ExternalEventResult::of((string) $existing->id, $event, false);
            }

            $event = ($this->submit)(
                $entityId, $eventType, 'external_event_intake', $intakeId, $idempotencyKey,
                $transactionDate, $effectiveDate, $currency, $payload, $dimensions,
            );
            DB::table('external_event_intakes')->where('id', $intakeId)->update(['accounting_event_id' => $event->id]);

            return ExternalEventResult::of($intakeId, $event, true);
        });

        if ($syncPost && $result->event->status->value === 'queued') {
            $journals = $this->engine->post($result->event->id);

            return ExternalEventResult::of($result->intakeId, $result->event->fresh() ?? $result->event, $result->created, $journals);
        }

        return $result;
    }
}
