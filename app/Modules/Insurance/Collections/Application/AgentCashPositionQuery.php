<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Platform\Authorization\AreaReach;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Agent cash position and deposit reconciliation (spec §4) as of a date: per agent, cash collected (agent collections received by then), deposited,
 * undeposited, the agent_receivable ledger balance for the agent and the difference, plus the oldest undeposited collection (deposits settle the
 * oldest collections first) and how long it has been held.
 */
final class AgentCashPositionQuery
{
    public function __construct(private readonly FinancialStatementsQuery $ledger) {}

    /** Cash the agent holds now: collections not bounced less deposits. */
    public function undepositedMinor(string $agentId): int
    {
        return (int) DB::table('receipts')->where('collected_by_agent_id', $agentId)->where('status', '<>', 'bounced')->sum('amount_minor')
            - (int) DB::table('agent_deposits')->where('agent_id', $agentId)->sum('amount_minor');
    }

    /**
     * @return array{entity_id: string, as_of: string, totals: array{collected_minor: int, deposited_minor: int, undeposited_minor: int, gl_minor: int},
     *     rows: list<array{agent_id: string, agent_code: string, collected_minor: int, deposited_minor: int, undeposited_minor: int, gl_minor: int,
     *     difference_minor: int, oldest_undeposited_on: string|null, days_undeposited: int|null}>}
     */
    public function position(string $entityId, CarbonImmutable $asOf, ?AreaReach $reach = null): array
    {
        $day = $asOf->toDateString();
        // Follow-up H1 (ASSUMPTION A-159): a branch-scoped user sees the agents whose branch is within reach, each with their whole position, so the GL by agent still matches.
        $inReach = $reach === null || $reach->tenantWide ? null
            : array_flip($reach->constrain(DB::table('producers as a')->join('branches as b', 'b.id', '=', 'a.branch_id'), 'b.entity_id', 'a.branch_id')->pluck('a.id')->map(fn ($id): string => (string) $id)->all());
        $collections = DB::table('receipts')->where('entity_id', $entityId)->whereNotNull('collected_by_agent_id')->where('value_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('bounced_on')->orWhere('bounced_on', '>', $day))
            ->orderBy('value_date')->get(['collected_by_agent_id', 'value_date', 'amount_minor'])->groupBy('collected_by_agent_id');
        $deposits = DB::table('agent_deposits')->where('entity_id', $entityId)->where('deposited_on', '<=', $day)
            ->groupBy('agent_id')->selectRaw('agent_id, sum(amount_minor) as total')->pluck('total', 'agent_id');
        $gl = $this->ledger->roleMovementByDimension($entityId, 'agent_receivable', CarbonImmutable::parse('1900-01-01'), $asOf, 'agent')['by_dimension'];
        $agentIds = array_values(array_unique([...$collections->keys()->map(fn ($id): string => (string) $id)->all(), ...$deposits->keys()->map(fn ($id): string => (string) $id)->all()]));
        if ($inReach !== null) {
            $agentIds = array_values(array_filter($agentIds, fn (string $id): bool => isset($inReach[$id])));
        }
        $codes = DB::table('producers')->whereIn('id', $agentIds)->pluck('code', 'id');

        $rows = [];
        foreach ($agentIds as $agentId) {
            $agentCollections = $collections->get($agentId, collect());
            $collected = (int) $agentCollections->sum('amount_minor');
            $deposited = (int) ($deposits[$agentId] ?? 0);
            $dated = [];
            foreach ($agentCollections as $collection) {
                $dated[] = [(string) $collection->value_date, (int) $collection->amount_minor];
            }
            $oldest = self::oldestUndeposited($dated, $deposited);
            $rows[] = ['agent_id' => $agentId, 'agent_code' => (string) ($codes[$agentId] ?? ''), 'collected_minor' => $collected, 'deposited_minor' => $deposited,
                'undeposited_minor' => $collected - $deposited, 'gl_minor' => $gl[$agentId] ?? 0, 'difference_minor' => ($collected - $deposited) - ($gl[$agentId] ?? 0),
                'oldest_undeposited_on' => $oldest, 'days_undeposited' => $oldest === null ? null : (int) CarbonImmutable::parse($oldest)->diffInDays($asOf)];
        }
        usort($rows, fn (array $a, array $b): int => $a['agent_code'] <=> $b['agent_code']);

        return ['entity_id' => $entityId, 'as_of' => $day, 'rows' => $rows, 'totals' => [
            'collected_minor' => array_sum(array_column($rows, 'collected_minor')), 'deposited_minor' => array_sum(array_column($rows, 'deposited_minor')),
            'undeposited_minor' => array_sum(array_column($rows, 'undeposited_minor')), 'gl_minor' => array_sum(array_column($rows, 'gl_minor')),
        ]];
    }

    /** @param list<array{0: string, 1: int}> $collections oldest first */
    private static function oldestUndeposited(array $collections, int $deposited): ?string
    {
        foreach ($collections as [$date, $amount]) {
            if ($deposited < $amount) {
                return $date;
            }
            $deposited -= $amount;
        }

        return null;
    }
}
