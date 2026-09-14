<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Provisions;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\ConditionalCloseTask;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use Illuminate\Support\Facades\DB;

/**
 * Market gap G5, close task "Technical provisions": in the close of a calendar quarter's last month the quarter's technical provisions run must be posted.
 * ASSUMPTION A-270: the task appears once the company has prepared its first technical provisions run, so a company not yet providing for IBNR in this system
 * closes its months as before.
 */
final class TechnicalProvisionsCloseCheck implements ConditionalCloseTask
{
    public function taskCode(): string
    {
        return 'technical_provisions';
    }

    public function appliesTo(FiscalPeriodView $period): bool
    {
        return $period->ends->month % 3 === 0 && DB::table('technical_provision_runs')->where('entity_id', $period->entityId)->exists();
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $quarterEnd = $period->ends->toDateString();
        $run = DB::table('technical_provision_runs')->where('entity_id', $period->entityId)->where('quarter_end', $quarterEnd)->first(['number', 'status', 'total_ibnr_minor', 'quarter_key']);
        $details = ['quarter_end' => $quarterEnd, 'run_number' => $run?->number, 'status' => $run?->status, 'total_ibnr_minor' => $run === null ? null : (int) $run->total_ibnr_minor];

        return match (true) {
            $run === null => CloseCheckResult::blocked('No technical provisions run for the quarter yet. Prepare it in Regulatory → Technical provisions.', $details),
            $run->status !== 'posted' => CloseCheckResult::blocked("The technical provisions run for {$run->quarter_key} is {$run->status}; it must be approved and posted.", $details),
            default => CloseCheckResult::passed("Technical provisions for {$run->quarter_key} posted.", $details),
        };
    }
}
