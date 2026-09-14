<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Infrastructure\Jobs;

use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Design §8.5 nightly batch, per tenant (D-07 loop): policies whose inception arrived become active, expired cover
 * expires, and every open period that has ended is earned (reruns are no-ops). Periods still running are not earned:
 * an earning month is credited when it ends.
 */
final class PremiumEarningJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(PolicyLifecycle $policies, FiscalPeriodQuery $periods, PremiumEarningRun $earning): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, function () use ($policies, $periods, $earning): void {
                $today = app(BusinessClock::class)->today(); // slice 2.1b: the company's today (D-54)
                $policies->activateDue($today);
                $policies->expireDue($today);
                foreach ($periods->openEndedBefore($today) as $period) {
                    $earning->run($period->id);
                }
            });
        }
    }
}
