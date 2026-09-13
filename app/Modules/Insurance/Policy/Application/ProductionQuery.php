<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Producer production for targets and incentives (Distribution design note §4 "achievement from premium register and collections"):
 * - premium: gross written premium of the producer's policies — new business, renewals, endorsements and cancellations dated in the period;
 * - policies: new and renewal policies dated in the period;
 * - collections: premium allocated to the producer's policies with a value date in the period, less nothing already reversed;
 * - persistency: 13th-month persistency on the period end (PersistencyQuery).
 */
final class ProductionQuery
{
    public function __construct(private readonly PersistencyQuery $persistency) {}

    /**
     * @param list<string>|null $producerIds null = every producer with production (persistency needs the list)
     * @return array<string, int> value by producer id
     */
    public function metric(string $metric, CarbonImmutable $from, CarbonImmutable $to, ?array $producerIds = null): array
    {
        [$start, $end] = [$from->toDateString(), $to->toDateString()];
        $rows = match ($metric) {
            'premium' => DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')->whereNotNull('p.agent_id')
                ->whereBetween('t.effective_date', [$start, $end])->groupBy('p.agent_id')->selectRaw('p.agent_id, sum(t.premium_delta_minor) as value'),
            'policies' => DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')->whereNotNull('p.agent_id')->whereIn('t.type', ['new', 'renewal'])
                ->whereBetween('t.effective_date', [$start, $end])->groupBy('p.agent_id')->selectRaw('p.agent_id, count(*) as value'),
            'collections' => DB::table('receipt_allocations as a')->join('policies as p', 'p.id', '=', 'a.policy_id')->whereNotNull('p.agent_id')->whereNull('a.reversed_on')
                ->whereBetween('a.posted_on', [$start, $end])->groupBy('p.agent_id')->selectRaw('p.agent_id, sum(a.amount_minor) as value'),
            default => null,
        };
        if ($metric === 'persistency') {
            $values = [];
            foreach ($producerIds ?? [] as $producerId) {
                $bp = $this->persistency->monthBp($producerId, $to);
                if ($bp !== null) {
                    $values[$producerId] = $bp;
                }
            }

            return $values;
        }
        if ($rows === null) {
            throw new \InvalidArgumentException("Unknown production metric {$metric}.");
        }

        /** @var array<string, int> */
        return $rows->when($producerIds !== null, fn ($q) => $q->whereIn('p.agent_id', $producerIds))->pluck('value', 'agent_id')->map(fn ($v): int => (int) $v)->all();
    }
}
