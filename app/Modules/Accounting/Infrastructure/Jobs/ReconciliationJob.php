<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.3 "Runs: nightly (recon queue) for all subledgers", per tenant (D-07): every started, unlocked period, as of its end or today.
 * Gap fix GA-05: recorded; finance can run it now.
 */
final class ReconciliationJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'reconciliation';

    public function __construct()
    {
        $this->onQueue('recon');
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        $this->eachTenant(fn (): array => $this->logged(self::KEY, function () use ($reconciliation): array {
            $today = app(BusinessClock::class)->today(); // slice 2.1b: the company's today (D-54)
            $periods = DB::table('fiscal_periods')->where('status', '<>', 'locked')->where('starts', '<=', $today->toDateString())
                ->orderBy('starts')->get(['id', 'ends']);
            $runs = 0;
            foreach ($periods as $period) {
                $ends = CarbonImmutable::parse((string) $period->ends);
                $runs += count($reconciliation->runAll((string) $period->id, $ends->lessThan($today) ? $ends : $today));
            }

            return ['periods' => count($periods), 'runs' => $runs];
        }));
    }
}
