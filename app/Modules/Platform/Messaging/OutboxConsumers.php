<?php

declare(strict_types=1);

namespace App\Modules\Platform\Messaging;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum §B.2.6 (PD-7, DECISION D-120): delivers outbox messages to their tagged consumers. Messages of a type nobody consumes stay unrelayed,
 * as before; messages written before a consumer existed are consumed on its first run (no backfill). A message whose handler throws stays unrelayed
 * and is retried on the next run; the other messages of the batch are still delivered (each in its own savepoint).
 */
final class OutboxConsumers
{
    private const BATCH_SIZE = 200;

    /** @param iterable<OutboxConsumer> $consumers */
    public function __construct(private readonly iterable $consumers) {}

    /** Every tenant. @return int messages delivered */
    public function deliverAll(): int
    {
        $delivered = 0;
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $delivered += TenantContext::run((string) $tenantId, fn (): int => $this->deliverCurrentTenant());
        }

        return $delivered;
    }

    /** The tenant in context. @return int messages delivered */
    public function deliverCurrentTenant(): int
    {
        $byType = [];
        foreach ($this->consumers as $consumer) {
            $byType[$consumer->messageType()] = $consumer;
        }
        if ($byType === []) {
            return 0;
        }

        return DB::transaction(function () use ($byType): int {
            $rows = DB::table('outbox')->whereNull('relayed_at')->whereIn('message_type', array_keys($byType))
                ->orderBy('created_at')->limit(self::BATCH_SIZE)->lock('for update skip locked')->get(['id', 'message_type', 'payload']);
            $delivered = 0;
            foreach ($rows as $row) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
                try {
                    DB::transaction(function () use ($byType, $row, $payload): void {
                        $byType[(string) $row->message_type]->handle($payload, (string) $row->id);
                        DB::table('outbox')->where('id', $row->id)->update(['relayed_at' => now()]);
                    });
                    $delivered++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $delivered;
        });
    }
}
