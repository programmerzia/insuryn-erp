<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Infrastructure\Jobs;

use App\Modules\Insurance\Renewal\Application\RenewalRun;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Slice R9: the expiry register, renewal quotations and renewal notices, nightly per tenant (D-07 loop). */
final class RenewalRunJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(RenewalRun $renewals): void
    {
        $today = CarbonImmutable::today();
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, fn (): array => $renewals->run($today));
        }
    }
}
