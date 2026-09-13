<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Producer persistency (Distribution design note §4 "persistency (13th/25th-month) reports", §2 step 4 conditions). ASSUMPTION A-23 — the
 * measure is not specified: the N-th-month persistency on a date is the share, in basis points, of the producer's new policies (not renewals)
 * whose inception is between N+12 and N months before the date and that are still in force (not cancelled or lapsed). No such policies → null
 * (not measurable yet, so a condition on it is not met).
 */
final class PersistencyQuery
{
    public function monthBp(string $producerId, CarbonImmutable $asOf, int $month = 13): ?int
    {
        $cohort = DB::table('policies')->where('agent_id', $producerId)->whereNull('renewal_of_policy_id')->whereNotIn('status', ['quote'])
            ->where('inception', '>', $asOf->subMonths($month + 12)->toDateString())->where('inception', '<=', $asOf->subMonths($month)->toDateString())
            ->selectRaw("count(*) as issued, count(*) filter (where status not in ('cancelled','lapsed')) as persisting")->first();
        $issued = (int) ($cohort->issued ?? 0);

        return $issued === 0 ? null : intdiv((int) ($cohort->persisting ?? 0) * 10_000, $issued);
    }
}
