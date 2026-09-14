<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §4 reports "renewal conversion by branch/producer, lapse reasons" (slice R9): the policies of an entity expiring in a period and what became
 * of each — renewed, not renewed (with the reason) or still open — with conversion per branch, producer or product, and the reasons policies were lost.
 *
 * ASSUMPTION: A-134 — expiring = issued policies (not quotes) whose expiry falls in the period, cancelled policies left out (they did not reach expiry);
 * renewed = a renewal policy naming it was issued; not renewed = recorded in the expiry register as lapsed or not renewed (its reason), lapsed for non-payment
 * (policy_lapsed), or expired before today without either (no_response); open = neither yet. Conversion = renewed ÷ expiring in basis points, rounded half up,
 * integers only.
 */
final class RenewalConversionQuery
{
    public const DIMENSIONS = ['branch', 'agent', 'product'];

    /**
     * @return array{rows: list<array{policy_id: string, policy_number: string, group: string, branch_code: string, producer_code: string|null, product_code: string, expiry: string,
     *     outcome: string, reason: string|null, renewal_policy_id: string|null, renewal_policy_number: string|null}>,
     *   groups: list<array{group: string, expiring: int, renewed: int, not_renewed: int, open: int, conversion_bp: int|null}>,
     *   reasons: list<array{reason: string, policies: int}>, totals: array{expiring: int, renewed: int, not_renewed: int, open: int, conversion_bp: int|null}}
     */
    public function conversion(string $entityId, CarbonImmutable $from, CarbonImmutable $to, string $by, CarbonImmutable $today): array
    {
        $by = in_array($by, self::DIMENSIONS, true) ? $by : 'branch';
        $policies = DB::table('policies as p')->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')->leftJoin('producers as a', 'a.id', '=', 'p.agent_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')->leftJoin('expiry_register as r', 'r.policy_id', '=', 'p.id')
            ->where('p.entity_id', $entityId)->whereNotIn('p.status', ['quote', 'cancelled'])->whereNotNull('p.number')
            ->whereBetween('p.expiry', [$from->toDateString(), $to->toDateString()])->orderBy('p.expiry')->orderBy('p.number')
            ->get(['p.id', 'p.number', 'p.status', 'p.expiry', 'b.code as branch_code', 'a.code as producer_code', 'pr.code as product_code', 'r.status as register_status', 'r.reason',
                DB::raw("(select n.id from policies n where n.renewal_of_policy_id = p.id and n.status <> 'quote' order by n.created_at desc limit 1) as renewal_id"),
                DB::raw("(select n.number from policies n where n.renewal_of_policy_id = p.id and n.status <> 'quote' order by n.created_at desc limit 1) as renewal_number")]);

        $rows = [];
        $groups = [];
        $reasons = [];
        foreach ($policies as $p) {
            [$outcome, $reason] = match (true) {
                $p->renewal_id !== null => ['renewed', null],
                in_array($p->register_status, ['lapsed', 'not_renewed'], true) => ['not_renewed', (string) $p->reason],
                $p->status === 'lapsed' => ['not_renewed', 'policy_lapsed'],
                (string) $p->expiry < $today->toDateString() => ['not_renewed', 'no_response'],
                default => ['open', null],
            };
            $group = match ($by) {
                'agent' => $p->producer_code === null ? 'Direct' : (string) $p->producer_code,
                'product' => (string) $p->product_code,
                default => (string) $p->branch_code,
            };
            $rows[] = ['policy_id' => (string) $p->id, 'policy_number' => (string) $p->number, 'group' => $group, 'branch_code' => (string) $p->branch_code,
                'producer_code' => $p->producer_code === null ? null : (string) $p->producer_code, 'product_code' => (string) $p->product_code, 'expiry' => (string) $p->expiry,
                'outcome' => $outcome, 'reason' => $reason, 'renewal_policy_id' => $p->renewal_id === null ? null : (string) $p->renewal_id,
                'renewal_policy_number' => $p->renewal_number === null ? null : (string) $p->renewal_number];
            $groups[$group] ??= ['group' => $group, 'expiring' => 0, 'renewed' => 0, 'not_renewed' => 0, 'open' => 0, 'conversion_bp' => null];
            $groups[$group]['expiring']++;
            $groups[$group][$outcome]++;
            if ($reason !== null) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        ksort($groups);
        ksort($reasons);
        $groups = array_values(array_map(fn (array $g): array => [...$g, 'conversion_bp' => self::conversionBp($g['renewed'], $g['expiring'])], $groups));
        $totals = ['expiring' => count($rows), 'renewed' => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'renewed')),
            'not_renewed' => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'not_renewed')), 'open' => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'open'))];

        return ['rows' => $rows, 'groups' => $groups, 'reasons' => array_map(fn (string $reason, int $count): array => ['reason' => $reason, 'policies' => $count], array_keys($reasons), $reasons),
            'totals' => [...$totals, 'conversion_bp' => self::conversionBp($totals['renewed'], $totals['expiring'])]];
    }

    /** renewed ÷ expiring in basis points, rounded half up with integers only; null when nothing expired. */
    public static function conversionBp(int $renewed, int $expiring): ?int
    {
        return $expiring === 0 ? null : intdiv($renewed * 20_000 + $expiring, 2 * $expiring);
    }
}
