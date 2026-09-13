<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Insurance\Policy\Application\PersistencyQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Distribution design note §4 persistency (13th/25th-month) report per producer on a date (A-23), producers with a measurable cohort only. */
final class PersistencyReport
{
    public function __construct(private readonly PersistencyQuery $persistency) {}

    /** @return list<array{producer_id: string, producer_code: string, month_13_bp: int|null, month_13_policies: int, month_25_bp: int|null, month_25_policies: int}> */
    public function rows(CarbonImmutable $asOf): array
    {
        $rows = [];
        foreach (DB::table('producers')->orderBy('code')->get(['id', 'code']) as $producer) {
            $cohort = fn (int $month): int => (int) DB::table('policies')->where('agent_id', $producer->id)->whereNull('renewal_of_policy_id')->where('status', '<>', 'quote')
                ->where('inception', '>', $asOf->subMonths($month + 12)->toDateString())->where('inception', '<=', $asOf->subMonths($month)->toDateString())->count();
            [$thirteen, $twentyFive] = [$cohort(13), $cohort(25)];
            if ($thirteen === 0 && $twentyFive === 0) {
                continue;
            }
            $rows[] = ['producer_id' => (string) $producer->id, 'producer_code' => (string) $producer->code,
                'month_13_bp' => $this->persistency->monthBp((string) $producer->id, $asOf, 13), 'month_13_policies' => $thirteen,
                'month_25_bp' => $this->persistency->monthBp((string) $producer->id, $asOf, 25), 'month_25_policies' => $twentyFive];
        }

        return $rows;
    }
}
