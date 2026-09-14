<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Infrastructure\Jobs;

use App\Modules\Distribution\Application\Licences\LicenceExpiryAlerts;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Distribution design note §3 licence-expiry alerts, nightly per tenant (D-07 loop), as of today. Gap fix GA-05: recorded; finance can run it now. */
final class LicenceExpiryAlertJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'licence_alerts';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(LicenceExpiryAlerts $alerts): void
    {
        // Slice 2.1b: the company's today, read inside the tenant (D-54).
        $this->eachTenant(fn (): int => $this->logged(self::KEY, fn (): int => $alerts->run(app(BusinessClock::class)->today())));
    }
}
