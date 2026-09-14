<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-14 ("no premium, no cover"): premium a bounced cheque was paying and that is still unpaid — per policy for its banner, and the policies in
 * force that have some, for the Home queue. An installment counts while it has something outstanding; once the customer pays again it drops out.
 */
final class BouncedPremiumQuery
{
    /**
     * The bounced cheques that left an installment of the policy unpaid, newest bounce first.
     *
     * @return list<array{receipt_id: string, receipt_number: string, cheque_no: string|null, bounced_on: string, bounce_reason: string|null, installment_no: int, outstanding_minor: int, currency: string}>
     */
    public function forPolicy(string $policyId): array
    {
        $rows = $this->unpaid()->where('a.policy_id', $policyId)->orderByDesc('r.bounced_on')->orderBy('i.no')
            ->get(['r.id', 'r.number', 'r.cheque_no', 'r.bounced_on', 'r.bounce_reason', 'i.no', 'r.currency', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = $row->id.'|'.$row->no;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['receipt_id' => (string) $row->id, 'receipt_number' => (string) $row->number, 'cheque_no' => $row->cheque_no === null ? null : (string) $row->cheque_no,
                'bounced_on' => (string) $row->bounced_on, 'bounce_reason' => $row->bounce_reason === null ? null : (string) $row->bounce_reason, 'installment_no' => (int) $row->no,
                'outstanding_minor' => (int) $row->outstanding, 'currency' => (string) $row->currency];
        }

        return $result;
    }

    /** Policies issued or active with premium from a bounced cheque still unpaid: id, number, policyholder, last bounce and what is unpaid on those installments. */
    public function policiesInForce(): Builder
    {
        // One row per installment first (a cheque bounced twice on the same installment counts it once), then per policy.
        $installments = $this->unpaid()->groupBy('a.policy_id', 'i.id')
            ->select(['a.policy_id', 'i.id as installment_id', DB::raw('max(r.bounced_on) as bounced_on'), DB::raw('max(i.amount_minor - i.paid_minor - i.cancelled_minor) as outstanding')]);

        $policies = DB::query()->fromSub($installments, 'u')->join('policies as p', 'p.id', '=', 'u.policy_id')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->whereIn('p.status', ['issued', 'active'])->groupBy('p.id', 'p.number', 'h.display_name', 'p.currency', 'p.entity_id', 'p.branch_id')
            ->select(['p.id', 'p.number', 'h.display_name', 'p.currency', 'p.entity_id', 'p.branch_id', DB::raw('max(u.bounced_on) as bounced_on'), DB::raw('sum(u.outstanding) as outstanding_minor')]);

        // Wrapped so count() counts policies, not groups; entity and branch let a caller limit it to a user's reach (b.entity_id, b.branch_id).
        return DB::query()->fromSub($policies, 'b')->orderByDesc('b.bounced_on')->orderBy('b.number')->select(['b.id', 'b.number', 'b.display_name', 'b.currency', 'b.bounced_on', 'b.outstanding_minor']);
    }

    private function unpaid(): Builder
    {
        return DB::table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')->join('installments as i', 'i.id', '=', 'a.target_id')
            ->where('r.status', 'bounced')->whereNotNull('a.reversed_on')->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0');
    }
}
