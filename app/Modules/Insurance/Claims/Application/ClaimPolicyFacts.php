<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-12: what the claims desk checks on the policy before registering and reserving — "no premium, no cover" (Insurance Act 2010 s.18 practice):
 * the cover period, the sum insured, premium billed, paid and still owed (and how much of it was already due on a date), cheques that bounced on it,
 * and the policy's other claims. Read-only; nothing here refuses a claim.
 */
final class ClaimPolicyFacts
{
    /**
     * @return array{id: string, number: string|null, status: string, product: string, inception: string, expiry: string, currency: string, sum_insured_minor: int|null,
     *     gross_premium_minor: int, paid_minor: int, outstanding_minor: int, overdue_minor: int, bounced_cheques: list<array{receipt_number: string, cheque_no: string|null, bounced_on: string|null, amount_minor: int}>,
     *     other_claims: list<array{id: string, number: string, loss_date: string, status: string, reserve_minor: int}>}|null
     */
    public function forPolicy(string $policyId, CarbonImmutable $dueBy, ?string $exceptClaimId = null): ?array
    {
        $policy = DB::table('policies as p')->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')->leftJoin('proposals as pp', 'pp.id', '=', 'p.proposal_id')->where('p.id', $policyId)
            ->first(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'p.currency', 'p.gross_premium_minor', 'pr.name as product', 'pp.sum_insured_minor']);
        if ($policy === null) {
            return null;
        }
        $installments = DB::table('installments')->where('policy_id', $policyId)
            ->selectRaw('coalesce(sum(amount_minor - cancelled_minor), 0) as billed, coalesce(sum(paid_minor), 0) as paid, coalesce(sum(amount_minor - paid_minor - cancelled_minor), 0) as outstanding')
            ->selectRaw('coalesce(sum(case when due_date <= ? then amount_minor - paid_minor - cancelled_minor else 0 end), 0) as overdue', [$dueBy->toDateString()])
            ->first();
        $bounced = DB::table('receipts as r')->where('r.status', 'bounced')
            ->whereExists(fn ($q) => $q->from('receipt_allocations as a')->whereColumn('a.receipt_id', 'r.id')->where('a.policy_id', $policyId))
            ->orderBy('r.bounced_on')->get(['r.number', 'r.cheque_no', 'r.bounced_on', 'r.amount_minor']);
        $claims = DB::table('claims')->where('policy_id', $policyId)->when($exceptClaimId !== null, fn ($q) => $q->where('id', '<>', $exceptClaimId))
            ->orderBy('loss_date')->get(['id', 'number', 'loss_date', 'status', 'reserve_minor']);

        return [
            'id' => (string) $policy->id, 'number' => $policy->number === null ? null : (string) $policy->number, 'status' => (string) $policy->status, 'product' => (string) ($policy->product ?? ''),
            'inception' => (string) $policy->inception, 'expiry' => (string) $policy->expiry, 'currency' => (string) $policy->currency,
            'sum_insured_minor' => $policy->sum_insured_minor === null ? null : (int) $policy->sum_insured_minor, 'gross_premium_minor' => (int) $policy->gross_premium_minor,
            'paid_minor' => (int) ($installments->paid ?? 0), 'outstanding_minor' => (int) ($installments->outstanding ?? 0), 'overdue_minor' => (int) ($installments->overdue ?? 0),
            'bounced_cheques' => array_values($bounced->map(fn (object $r): array => ['receipt_number' => (string) $r->number, 'cheque_no' => $r->cheque_no === null ? null : (string) $r->cheque_no,
                'bounced_on' => $r->bounced_on === null ? null : (string) $r->bounced_on, 'amount_minor' => (int) $r->amount_minor])->all()),
            'other_claims' => array_values($claims->map(fn (object $c): array => ['id' => (string) $c->id, 'number' => (string) $c->number, 'loss_date' => (string) $c->loss_date, 'status' => (string) $c->status,
                'reserve_minor' => (int) $c->reserve_minor])->all()),
        ];
    }
}
