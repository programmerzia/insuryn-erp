<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Infrastructure\Jobs;

use App\Modules\Distribution\Application\Licences\LicenceExpiryAlerts;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Distribution design note §3 licence-expiry alerts, nightly per tenant (D-07 loop), as of today. */
final class LicenceExpiryAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(LicenceExpiryAlerts $alerts): void
    {
        $today = CarbonImmutable::today();
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, fn () => $alerts->run($today));
        }
    }
}
