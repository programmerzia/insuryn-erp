<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Infrastructure\Jobs;

use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Slice R6: active cover notes past their last day expire, nightly per tenant (D-07 loop). */
final class CoverNoteExpiryJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(CoverNoteService $coverNotes): void
    {
        $today = CarbonImmutable::today();
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, fn (): int => $coverNotes->expireDue($today));
        }
    }
}
