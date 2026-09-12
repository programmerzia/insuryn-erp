<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Design §8.2: guarantee delivery of PostAccountingEvent even if the afterCommit dispatch was lost.
 * Schedule every second (or run as a long-lived command). Runs OUTSIDE tenant context on purpose:
 * it needs to see all tenants' outbox rows, so it must run on a connection that bypasses RLS
 * (the migration owner role) — OPEN: decide between a bypass role or a per-tenant relay loop.
 */
final class OutboxRelayJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function handle(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('outbox')->whereNull('relayed_at')->where('message_type', 'PostAccountingEvent')
                ->orderBy('created_at')->limit(100)->lockForUpdate()->get();
            foreach ($rows as $row) {
                /** @var array{event_id:string} $p */
                $p = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
                PostAccountingEventJob::dispatch((string) $row->tenant_id, $p['event_id'])->onQueue('posting');
                DB::table('outbox')->where('id', $row->id)->update(['relayed_at' => now()]);
            }
        });
    }
}
