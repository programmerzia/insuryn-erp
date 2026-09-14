<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Infrastructure\Jobs;

use App\Modules\Insurance\Renewal\Application\RenewalRun;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
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
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            // Slice 2.1b: the company's today, read inside the tenant (D-54).
            TenantContext::run((string) $tenantId, fn (): array => $renewals->run(app(BusinessClock::class)->today()));
        }
    }
}
