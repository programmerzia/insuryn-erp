<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Infrastructure\Jobs;

use App\Modules\Insurance\Renewal\Application\RenewalRun;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Slice R9: the expiry register, renewal quotations and renewal notices, nightly per tenant (D-07 loop). Gap fix GA-05: recorded; finance can run it now. */
final class RenewalRunJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'renewals';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(RenewalRun $renewals): void
    {
        // Slice 2.1b: the company's today, read inside the tenant (D-54).
        $this->eachTenant(fn (): array => $this->logged(self::KEY, fn (): array => $renewals->run(app(BusinessClock::class)->today())));
    }
}
