<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Design §8.5 ReservationSweeper (default queue, every 15 minutes): voids document-number reservations
 * abandoned by rolled-back business transactions. Per-tenant loop over the platform `tenants` table (D-07).
 */
final class ReservationSweeperJob implements ShouldQueue
{
    use Queueable;

    public function handle(DocumentNumberer $numberer): void
    {
        $ttlMinutes = (int) config('erp.numbering.reservation_ttl_minutes', 15);

        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, fn (): int => $numberer->voidExpiredReservations($ttlMinutes));
        }
    }
}
