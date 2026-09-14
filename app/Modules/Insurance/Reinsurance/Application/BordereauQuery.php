<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use Illuminate\Support\Facades\DB;

/**
 * Bordereaux for reinsurers: the premium bordereau lists every cession movement dated in the period (policy, insured, class, cover, sum insured, the
 * reinsurer's share, ceded sum insured, ceded premium, commission, net due); the claims bordereau lists reinsurers' shares of claim reserves and payments
 * recorded in the period, with the claim's gross figures. Rows are sorted by reinsurer, so one download serves every reinsurer.
 */
final class BordereauQuery
{
    /**
     * @return list<array{reinsurer: string, reinsurer_code: string, kind: string, treaty: string|null, movement: string, accounting_date: string, policy_id: string, policy_number: string,
     *     insured: string, class_code: string|null, inception: string, expiry: string, sum_insured_minor: int, share_bp: int, ceded_sum_insured_minor: int, premium_minor: int,
     *     commission_minor: int, net_minor: int}>
     */
    public function premium(string $entityId, string $from, string $to, ?string $reinsurerId = null): array
    {
        return array_values(DB::table('ri_cessions as c')->join('reinsurers as r', 'r.id', '=', 'c.reinsurer_id')->join('parties as rp', 'rp.id', '=', 'r.party_id')
            ->join('policies as p', 'p.id', '=', 'c.policy_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->join('product_versions as pv', 'pv.id', '=', 'p.product_version_id')->leftJoin('ri_treaties as t', 't.id', '=', 'c.treaty_id')
            ->leftJoin('ri_policy_positions as pos', 'pos.policy_id', '=', 'p.id')
            ->where('c.entity_id', $entityId)->whereBetween('c.accounting_date', [$from, $to])->when($reinsurerId !== null, fn ($q) => $q->where('c.reinsurer_id', $reinsurerId))
            ->orderBy('r.code')->orderBy('c.accounting_date')->orderBy('p.number')->orderBy('c.created_at')
            ->get(['rp.display_name as reinsurer', 'r.code as reinsurer_code', 'c.kind', 't.code as treaty', 'c.movement', 'c.accounting_date', 'p.id as policy_id', 'p.number as policy_number',
                'h.display_name as insured', 'pv.class_code', 'p.inception', 'p.expiry', 'pos.sum_insured_minor', 'c.share_bp', 'c.ceded_sum_insured_minor', 'c.premium_minor', 'c.commission_minor'])
            ->map(fn (object $r): array => ['reinsurer' => (string) $r->reinsurer, 'reinsurer_code' => (string) $r->reinsurer_code, 'kind' => (string) $r->kind,
                'treaty' => $r->treaty === null ? null : (string) $r->treaty, 'movement' => (string) $r->movement, 'accounting_date' => (string) $r->accounting_date,
                'policy_id' => (string) $r->policy_id, 'policy_number' => (string) $r->policy_number, 'insured' => (string) $r->insured, 'class_code' => $r->class_code === null ? null : (string) $r->class_code,
                'inception' => (string) $r->inception, 'expiry' => (string) $r->expiry, 'sum_insured_minor' => (int) $r->sum_insured_minor, 'share_bp' => (int) $r->share_bp,
                'ceded_sum_insured_minor' => (int) $r->ceded_sum_insured_minor, 'premium_minor' => (int) $r->premium_minor, 'commission_minor' => (int) $r->commission_minor,
                'net_minor' => (int) $r->premium_minor - (int) $r->commission_minor])->all());
    }

    /**
     * @return list<array{reinsurer: string, reinsurer_code: string, recorded_on: string, claim_id: string, claim_number: string, policy_number: string, loss_date: string,
     *     status: string, kind: string, share_bp: int, gross_minor: int, amount_minor: int, gross_reserve_minor: int}>
     */
    public function claims(string $entityId, string $from, string $to, ?string $reinsurerId = null): array
    {
        return array_values(DB::table('ri_claim_shares as s')->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')->join('parties as rp', 'rp.id', '=', 'r.party_id')
            ->join('claims as c', 'c.id', '=', 's.claim_id')->join('policies as p', 'p.id', '=', 's.policy_id')
            ->where('s.entity_id', $entityId)->whereBetween('s.recorded_on', [$from, $to])->when($reinsurerId !== null, fn ($q) => $q->where('s.reinsurer_id', $reinsurerId))
            ->orderBy('r.code')->orderBy('s.recorded_on')->orderBy('c.number')->orderBy('s.created_at')
            ->get(['rp.display_name as reinsurer', 'r.code as reinsurer_code', 's.recorded_on', 'c.id as claim_id', 'c.number as claim_number', 'p.number as policy_number', 'c.loss_date',
                'c.status', 's.kind', 's.share_bp', 's.gross_minor', 's.amount_minor', 'c.reserve_minor'])
            ->map(fn (object $r): array => ['reinsurer' => (string) $r->reinsurer, 'reinsurer_code' => (string) $r->reinsurer_code, 'recorded_on' => (string) $r->recorded_on,
                'claim_id' => (string) $r->claim_id, 'claim_number' => (string) $r->claim_number, 'policy_number' => (string) $r->policy_number, 'loss_date' => (string) $r->loss_date,
                'status' => (string) $r->status, 'kind' => (string) $r->kind, 'share_bp' => (int) $r->share_bp, 'gross_minor' => (int) $r->gross_minor, 'amount_minor' => (int) $r->amount_minor,
                'gross_reserve_minor' => (int) $r->reserve_minor])->all());
    }
}
