<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Infrastructure\Jobs;

use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Design §8.5 nightly batch, per tenant (D-07 loop): policies whose inception arrived become active, expired cover
 * expires, and every open period that has ended is earned (reruns are no-ops). Periods still running are not earned:
 * an earning month is credited when it ends. Gap fix GA-05: each tenant's run is recorded (JobRunLog) and finance can run it now.
 */
final class PremiumEarningJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'policy_lifecycle';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(PolicyLifecycle $policies, FiscalPeriodQuery $periods, PremiumEarningRun $earning): void
    {
        $this->eachTenant(fn (): array => $this->logged(self::KEY, function () use ($policies, $periods, $earning): array {
            $today = app(BusinessClock::class)->today(); // slice 2.1b: the company's today (D-54)
            $result = ['activated' => $policies->activateDue($today), 'expired' => $policies->expireDue($today), 'periods_earned' => 0, 'policies_earned' => 0];
            foreach ($periods->openEndedBefore($today) as $period) {
                $run = $earning->run($period->id);
                $result['periods_earned']++;
                $result['policies_earned'] += $run->policiesEarned;
            }

            return $result;
        }));
    }
}
