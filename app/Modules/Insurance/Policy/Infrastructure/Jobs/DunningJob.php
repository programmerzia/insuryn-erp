<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Infrastructure\Jobs;

use App\Modules\Insurance\Policy\Application\Dunning\DunningRun;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Spec §4 dunning and auto-lapse, nightly per tenant (D-07 loop) and entity, as of today. */
final class DunningJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(DunningRun $dunning): void
    {
        $today = CarbonImmutable::today();
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, function () use ($dunning, $today): void {
                foreach (DB::table('legal_entities')->orderBy('code')->pluck('id') as $entityId) {
                    $dunning->run((string) $entityId, $today);
                }
            });
        }
    }
}
