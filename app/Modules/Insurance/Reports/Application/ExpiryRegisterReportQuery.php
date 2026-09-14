<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §4 report "expiry register" (slice R9): the policies of an entity in force on a date that expire within the largest renewal bucket after it
 * (erp.renewals.buckets), with their bucket, days left, where the renewal stands and their gross premium; totals by bucket, branch and producer.
 * ASSUMPTION: A-134 — "in force on the date": issued on or before it (not a quote) and not cancelled on or before it; the renewal status is the register's, or
 * `renewed` when a renewal policy was issued, else `upcoming` for a policy the register has not listed yet. Amounts in minor units; counts are integers.
 */
final class ExpiryRegisterReportQuery
{
    /**
     * @return array{as_of: string, rows: list<array{policy_id: string, policy_number: string, customer: string, product_code: string, branch_code: string, producer_code: string|null,
     *     expiry: string, days_left: int, bucket: int|null, status: string, renewal_quotation: string|null, gross_minor: int}>,
     *   totals: array{policies: int, gross_minor: int}, by_bucket: list<array{group: string, policies: int, gross_minor: int}>,
     *   by_branch: list<array{group: string, policies: int, gross_minor: int}>, by_producer: list<array{group: string, policies: int, gross_minor: int}>}
     */
    public function register(string $entityId, CarbonImmutable $asOf): array
    {
        $buckets = array_values(array_filter(array_map('intval', (array) config('erp.renewals.buckets', [60, 30, 15, 7])), fn (int $b): bool => $b > 0));
        sort($buckets);
        $last = $asOf->addDays($buckets === [] ? 60 : max($buckets));
        $day = $asOf->toDateString();
        $policies = DB::table('policies as p')->leftJoin('parties as c', 'c.id', '=', 'p.policyholder_party_id')->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')->leftJoin('producers as a', 'a.id', '=', 'p.agent_id')
            ->leftJoin('expiry_register as r', 'r.policy_id', '=', 'p.id')->leftJoin('quotations as q', 'q.id', '=', 'r.renewal_quotation_id')
            ->where('p.entity_id', $entityId)->where('p.status', '<>', 'quote')->whereNotNull('p.issued_at')->whereRaw('p.issued_at::date <= ?', [$day])
            ->where(fn ($q) => $q->whereNull('p.cancel_date')->orWhere('p.cancel_date', '>', $day))
            ->whereBetween('p.expiry', [$day, $last->toDateString()])
            ->orderBy('p.expiry')->orderBy('p.number')
            ->get(['p.id', 'p.number', 'c.display_name as customer', 'pr.code as product_code', 'b.code as branch_code', 'a.code as producer_code', 'p.expiry', 'p.gross_premium_minor',
                'r.status as register_status', 'q.number as quotation_number',
                DB::raw("exists (select 1 from policies n where n.renewal_of_policy_id = p.id and n.status <> 'quote') as renewed")]);

        $rows = [];
        $groups = ['bucket' => [], 'branch' => [], 'producer' => []];
        foreach ($policies as $p) {
            $daysLeft = (int) $asOf->diffInDays(CarbonImmutable::parse((string) $p->expiry), false);
            $bucket = null;
            foreach ($buckets as $candidate) {
                if ($daysLeft <= $candidate) {
                    $bucket = $candidate;
                    break;
                }
            }
            $row = ['policy_id' => (string) $p->id, 'policy_number' => (string) $p->number, 'customer' => (string) $p->customer, 'product_code' => (string) $p->product_code,
                'branch_code' => (string) $p->branch_code, 'producer_code' => $p->producer_code === null ? null : (string) $p->producer_code, 'expiry' => (string) $p->expiry,
                'days_left' => $daysLeft, 'bucket' => $bucket, 'status' => (bool) $p->renewed ? 'renewed' : (string) ($p->register_status ?? 'upcoming'),
                'renewal_quotation' => $p->quotation_number === null ? null : (string) $p->quotation_number, 'gross_minor' => (int) $p->gross_premium_minor];
            $rows[] = $row;
            foreach (['bucket' => $bucket === null ? '—' : "{$bucket} days", 'branch' => $row['branch_code'], 'producer' => $row['producer_code'] ?? 'Direct'] as $dimension => $group) {
                $groups[$dimension][$group] ??= ['group' => $group, 'policies' => 0, 'gross_minor' => 0];
                $groups[$dimension][$group]['policies']++;
                $groups[$dimension][$group]['gross_minor'] += $row['gross_minor'];
            }
        }
        $ordered = function (array $group, ?callable $sort = null): array {
            $sort === null ? ksort($group) : uksort($group, $sort);

            return array_values($group);
        };

        return ['as_of' => $day, 'rows' => $rows, 'totals' => ['policies' => count($rows), 'gross_minor' => array_sum(array_column($rows, 'gross_minor'))],
            'by_bucket' => $ordered($groups['bucket'], fn (string $a, string $b): int => (int) $a <=> (int) $b), 'by_branch' => $ordered($groups['branch']), 'by_producer' => $ordered($groups['producer'])];
    }
}
