<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Domain\Enums\EventStatus;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY entry point for business modules into the kernel (design §8.2).
 * MUST be called inside the caller's DB transaction so source row + event + outbox commit together.
 * Idempotent: a duplicate idempotency_key returns the existing event.
 */
final class SubmitAccountingEvent
{
    public function __construct(private readonly PostingDispatcher $dispatcher) {}

    /**
     * @param array<string,mixed> $payload   amounts in minor units
     * @param array<string,mixed> $dimensions e.g. ['branch'=>uuid,'product'=>uuid,'policy'=>uuid,'customer'=>uuid,'agent'=>uuid,'product_code'=>'MOTOR','lob'=>'motor','channel'=>'agent']
     */
    public function __invoke(
        string $entityId,
        string $eventType,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        CarbonImmutable $transactionDate,
        CarbonImmutable $effectiveDate,
        string $currency,
        array $payload,
        array $dimensions,
        int $sourceVersion = 1,
    ): AccountingEvent {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('SubmitAccountingEvent must run inside the source transaction.');
        }
        $tenantId = TenantContext::id();
        $id = (string) Str::uuid7();

        $inserted = DB::table('accounting_events')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'event_type' => $eventType,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_version' => $sourceVersion,
            'idempotency_key' => $idempotencyKey, 'occurred_at' => now(), 'transaction_date' => $transactionDate->toDateString(),
            'effective_date' => $effectiveDate->toDateString(), 'currency' => $currency,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
            'status' => EventStatus::Queued->value, 'created_at' => now(),
        ]);

        if ($inserted === 0) { // duplicate key → idempotent no-op
            return AccountingEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        DB::table('outbox')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'message_type' => 'PostAccountingEvent',
            'payload' => json_encode(['event_id' => $id], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        // Fast path; the outbox relay is the guarantee if this dispatch is lost.
        $this->dispatcher->dispatchAfterCommit($tenantId, $id);

        return AccountingEvent::query()->findOrFail($id);
    }
}
