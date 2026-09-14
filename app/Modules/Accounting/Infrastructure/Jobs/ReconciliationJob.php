<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Design §6.3 "Runs: nightly (recon queue) for all subledgers", per tenant (D-07): every started, unlocked period, as of its end or today. */
final class ReconciliationJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('recon');
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, function () use ($reconciliation): void {
                $today = app(BusinessClock::class)->today(); // slice 2.1b: the company's today (D-54)
                $periods = DB::table('fiscal_periods')->where('status', '<>', 'locked')->where('starts', '<=', $today->toDateString())
                    ->orderBy('starts')->get(['id', 'ends']);
                foreach ($periods as $period) {
                    $ends = CarbonImmutable::parse((string) $period->ends);
                    $reconciliation->runAll((string) $period->id, $ends->lessThan($today) ? $ends : $today);
                }
            });
        }
    }
}
