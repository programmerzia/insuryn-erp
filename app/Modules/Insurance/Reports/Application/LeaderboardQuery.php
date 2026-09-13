<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Policy\Application\ProductionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Distribution design note §4 leaderboards: producers ranked by a production metric over a period (ties share a rank), with their target when
 * the period is exactly a target period (calendar month, quarter or year) and the achievement against it.
 */
final class LeaderboardQuery
{
    public function __construct(
        private readonly ProductionQuery $production,
        private readonly TargetService $targets,
    ) {}

    /** @return list<array{rank: int, producer_id: string, producer_code: string, producer_name: string, producer_type: string, value: int, target: int|null, achievement_bp: int|null}> */
    public function rows(string $metric, CarbonImmutable $from, CarbonImmutable $to, ?string $channelId = null, ?string $branchId = null): array
    {
        $producers = DB::table('producers as p')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')
            ->when($channelId !== null, fn ($q) => $q->where('p.channel_id', $channelId))->when($branchId !== null, fn ($q) => $q->where('p.branch_id', $branchId))
            ->get(['p.id', 'p.code', 'p.type', 'pa.display_name'])->keyBy('id');
        $values = $this->production->metric($metric, $from, $to, $metric === 'persistency' ? array_values(array_map('strval', $producers->keys()->all())) : null);
        $periodType = match (true) {
            $from->day === 1 && $to->isSameDay($from->endOfMonth()) => 'monthly',
            $from->day === 1 && in_array($from->month, [1, 4, 7, 10], true) && $to->isSameDay($from->addMonths(2)->endOfMonth()) => 'quarterly',
            $from->day === 1 && $from->month === 1 && $to->isSameDay($from->endOfYear()) => 'annual',
            default => null,
        };

        $rows = [];
        foreach ($values as $producerId => $value) {
            $producer = $producers->get($producerId);
            if ($producer === null || $value === 0) {
                continue;
            }
            $target = $periodType === null ? null : $this->targets->valueFor('producer', (string) $producerId, $periodType, $from, $metric);
            $rows[] = ['rank' => 0, 'producer_id' => (string) $producerId, 'producer_code' => (string) $producer->code, 'producer_name' => (string) $producer->display_name,
                'producer_type' => (string) $producer->type, 'value' => $value, 'target' => $target, 'achievement_bp' => $target === null ? null : intdiv(max(0, $value) * 10_000, $target)];
        }
        usort($rows, fn (array $a, array $b): int => [$b['value'], $a['producer_code']] <=> [$a['value'], $b['producer_code']]);
        foreach ($rows as $i => $row) {
            $rows[$i]['rank'] = $i > 0 && $rows[$i - 1]['value'] === $row['value'] ? $rows[$i - 1]['rank'] : $i + 1;
        }

        return $rows;
    }
}
