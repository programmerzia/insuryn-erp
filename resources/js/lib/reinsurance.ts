/** Reinsurance MVP (G4): the policy page's Reinsurance tab data (ReinsurancePageController::policyTab). */
export interface PolicyReinsurance {
    position: { treaty: string | null; treaty_id: string | null; treaty_type: string | null; sum_insured: string; net_premium: string; sbc: string; treaty_ceded: string; retained: string; above_capacity: string; above_capacity_minor: number; note: string | null } | null;
    shares: ShareRow[];
    movements: MovementRow[];
    placements: PlacementRow[];
    claims: ClaimShareRow[];
    canPlace: boolean;
    reinsurers: { value: string; label: string }[];
    today: string;
}
export interface ShareRow { basis: string; reinsurer: string; share: string; ceded_sum_insured: string; premium: string; commission: string }
export interface MovementRow { id: string; date: string; movement: string; basis: string; reinsurer: string; ceded_sum_insured: string; premium: string; commission: string }
export interface PlacementRow { id: string; reinsurer: string; slip_reference: string | null; share: string; ceded_sum_insured: string; premium: string; commission: string; placed_on: string }
export interface ClaimShareRow { claim_id: string; claim_number: string; reinsurer: string; outstanding: string; recoverable: string }

/** A cession movement as the cessions list, the treaty page and the statement page show it (ReinsurancePageController::cessionRow). */
export interface CessionRow {
    id: string; kind: string; movement: string; date: string; share: string; ceded_sum_insured: string; premium: string; commission: string; policy_id: string; policy_number: string;
    insured: string; reinsurer: string; treaty_id: string | null; treaty: string | null;
}

/** A quarterly reinsurer statement (ReinsurancePageController::statementRow). */
export interface StatementRow {
    id: string; number: string; reinsurer: string; quarter: string; period_from: string; period_to: string; opening: string; premium: string; commission: string; claims_recoverable: string;
    closing: string; outstanding_claims_share: string; due_to_reinsurer: boolean; bordereau: string; claims_bordereau: string;
}

export const CESSION_BASES = ['SBC compulsory', 'Quota share', 'Surplus', 'Facultative'];
export const CESSION_MOVEMENTS = ['Issue', 'Endorsement', 'Cancellation', 'Placement', 'Backfill'];
