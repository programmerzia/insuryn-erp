<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Unearned premium register as at a date, per policy, with totals by class and by product, reconciled to the unearned_premium control.
 * Built from dated business rows the way the ledger posts them (A-8): issue and endorsements credit their net premium on their accounting date
 * (POLICY_ISSUED, POLICY_ENDORSED), each earning row moves its amount to income on its posting date (a scheduled row on its period's end, a
 * cancellation catch-up on the cancellation date — PREMIUM_EARNED), and a cancellation releases the unearned remainder on its date (POLICY_CANCELLED).
 * Unearned = net written − earned − released; so, while nothing else posts to the control, the register equals the GL balance.
 *
 * ASSUMPTION A-61: unearned premium is dated as the ledger posts it (above); a manual journal or reversal on the control is not in the register
 * and shows as the reconciliation variance.
 *
 * ASSUMPTION A-60: "class" is the product's line of business (`products.lob`: motor, fire, marine, …), not `products.insurance_class`
 * (life / non-life), which would put all non-life business in one group.
 */
final class UnearnedPremiumQuery
{
    public function __construct(private readonly FinancialStatementsQuery $statements) {}

    /**
     * @return array{entity_id: string, as_of: string,
     *     rows: list<array{policy_id: string, policy_number: string|null, product_id: string, product_code: string, class: string, branch_id: string, branch_code: string,
     *     net_premium_minor: int, earned_minor: int, released_minor: int, unearned_minor: int}>,
     *     totals: array{net_premium_minor: int, earned_minor: int, unearned_minor: int},
     *     by_class: list<array{group: string, policies: int, net_premium_minor: int, earned_minor: int, unearned_minor: int}>,
     *     by_product: list<array{group: string, policies: int, net_premium_minor: int, earned_minor: int, unearned_minor: int}>,
     *     reconciliation: array{register_minor: int, gl_minor: int, variance_minor: int, account_ids: list<string>}}
     */
    public function unearned(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $written = DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')
            ->where('p.entity_id', $entityId)->whereIn('t.type', ['new', 'endorsement'])->where('t.accounting_date', '<=', $day)
            ->selectRaw('t.policy_id, t.net_delta_minor as written, 0 as earned, 0 as released');
        $released = DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')
            ->where('p.entity_id', $entityId)->where('t.type', 'cancellation')->where('t.accounting_date', '<=', $day)
            ->selectRaw("t.policy_id, 0 as written, 0 as earned, coalesce((t.amounts->>'unearned_remaining')::bigint, 0) as released");
        $earned = DB::table('premium_earning_ledger as e')->join('policies as p', 'p.id', '=', 'e.policy_id')->join('fiscal_periods as fp', 'fp.id', '=', 'e.period_id')
            ->where('p.entity_id', $entityId)
            ->whereRaw("(case when e.kind = 'scheduled' then fp.ends else coalesce((select max(c.accounting_date) from policy_transactions c
                where c.policy_id = e.policy_id and c.type = 'cancellation'), fp.ends) end) <= ?", [$day])
            ->selectRaw('e.policy_id, 0 as written, e.earned_minor as earned, 0 as released');

        $movements = DB::query()->fromSub($written->unionAll($released)->unionAll($earned), 'm')->groupBy('m.policy_id')
            ->selectRaw('m.policy_id, sum(m.written) as written, sum(m.earned) as earned, sum(m.released) as released');
        $policies = DB::query()->fromSub($movements, 'u')->join('policies as p', 'p.id', '=', 'u.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->join('branches as b', 'b.id', '=', 'p.branch_id')->whereRaw('u.written - u.earned - u.released <> 0')
            ->orderBy('pr.lob')->orderBy('pr.code')->orderBy('p.number')
            ->get(['u.policy_id', 'u.written', 'u.earned', 'u.released', 'p.number', 'pr.id as product_id', 'pr.code as product_code', 'pr.lob', 'p.branch_id', 'b.code as branch_code']);

        $rows = [];
        $totals = ['net_premium_minor' => 0, 'earned_minor' => 0, 'unearned_minor' => 0];
        $byClass = [];
        $byProduct = [];
        foreach ($policies as $p) {
            /** @var object{policy_id: string, written: int|string, earned: int|string, released: int|string, number: string|null, product_id: string, product_code: string,
             *     lob: string, branch_id: string, branch_code: string} $p */
            $row = ['policy_id' => (string) $p->policy_id, 'policy_number' => $p->number === null ? null : (string) $p->number, 'product_id' => (string) $p->product_id,
                'product_code' => (string) $p->product_code, 'class' => (string) $p->lob, 'branch_id' => (string) $p->branch_id, 'branch_code' => (string) $p->branch_code,
                'net_premium_minor' => (int) $p->written, 'earned_minor' => (int) $p->earned, 'released_minor' => (int) $p->released,
                'unearned_minor' => (int) $p->written - (int) $p->earned - (int) $p->released];
            $rows[] = $row;
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
            $byClass = self::accumulate($byClass, $row['class'], $row);
            $byProduct = self::accumulate($byProduct, $row['product_code'], $row);
        }
        ksort($byClass);
        ksort($byProduct);
        $gl = $this->statements->roleBalanceByDimension($entityId, 'unearned_premium', $asOf, 'policy');

        return ['entity_id' => $entityId, 'as_of' => $day, 'rows' => $rows, 'totals' => $totals,
            'by_class' => array_values($byClass), 'by_product' => array_values($byProduct),
            'reconciliation' => ['register_minor' => $totals['unearned_minor'], 'gl_minor' => $gl['balance_minor'],
                'variance_minor' => $totals['unearned_minor'] - $gl['balance_minor'], 'account_ids' => $gl['account_ids']]];
    }

    /**
     * @param array<string, array{group: string, policies: int, net_premium_minor: int, earned_minor: int, unearned_minor: int}> $groups
     * @param array{net_premium_minor: int, earned_minor: int, unearned_minor: int} $row
     * @return array<string, array{group: string, policies: int, net_premium_minor: int, earned_minor: int, unearned_minor: int}>
     */
    private static function accumulate(array $groups, string $group, array $row): array
    {
        $current = $groups[$group] ?? ['group' => $group, 'policies' => 0, 'net_premium_minor' => 0, 'earned_minor' => 0, 'unearned_minor' => 0];
        $groups[$group] = ['group' => $group, 'policies' => $current['policies'] + 1, 'net_premium_minor' => $current['net_premium_minor'] + $row['net_premium_minor'],
            'earned_minor' => $current['earned_minor'] + $row['earned_minor'], 'unearned_minor' => $current['unearned_minor'] + $row['unearned_minor']];

        return $groups;
    }
}
