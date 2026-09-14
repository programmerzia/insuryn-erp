<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Design §8.2: guarantees delivery of PostAccountingEvent when the afterCommit fast path was lost.
 * Schedule every second (or run as a long-lived command).
 *
 * D-07: relays tenant by tenant. `tenants` is a platform table outside RLS (§8.6.5), so the relay
 * lists tenants and then works inside each tenant's context — no RLS-bypass role. Delivery is
 * at-least-once; PostingEngine's status CAS makes a re-delivered event a no-op (§8.3).
 */
final class OutboxRelayJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    private const BATCH_SIZE = 100;

    public function handle(PostingDispatcher $dispatcher): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, fn () => $this->relayTenant((string) $tenantId, $dispatcher));
        }
    }

    private function relayTenant(string $tenantId, PostingDispatcher $dispatcher): void
    {
        DB::transaction(function () use ($tenantId, $dispatcher): void {
            $rows = DB::table('outbox')->whereNull('relayed_at')->where('message_type', 'PostAccountingEvent')
                ->orderBy('created_at')->limit(self::BATCH_SIZE)->lock('for update skip locked')->get(['id', 'payload']);

            foreach ($rows as $row) {
                /** @var array{event_id: string} $payload */
                $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
                $dispatcher->dispatchAfterCommit($tenantId, $payload['event_id']);
            }
            DB::table('outbox')->whereIn('id', $rows->pluck('id'))->update(['relayed_at' => now()]);

            // Gap audit GA-46 (ASSUMPTION A-186): announcements nothing subscribes to in this build are marked relayed (delivered to nobody), so the
            // unrelayed backlog holds only messages that wait for a consumer.
            $noSubscriber = DB::table('outbox')->whereNull('relayed_at')->whereIn('message_type', (array) config('erp.outbox.no_subscriber_types', []))
                ->orderBy('created_at')->limit(self::BATCH_SIZE * 10)->lock('for update skip locked')->pluck('id');
            DB::table('outbox')->whereIn('id', $noSubscriber)->update(['relayed_at' => now()]);
        });
    }
}
