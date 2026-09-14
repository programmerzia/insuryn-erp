/** Reinsurance MVP (G4): the policy page's Reinsurance tab data (ReinsurancePageController::policyTab). */
export interface PolicyReinsurance {
    position: { treaty: string | null; treaty_type: string | null; sum_insured: string; net_premium: string; sbc: string; treaty_ceded: string; retained: string; above_capacity: string; above_capacity_minor: number; note: string | null } | null;
    shares: { basis: string; reinsurer: string; share: string; ceded_sum_insured: string; premium: string; commission: string }[];
    movements: { id: string; date: string; movement: string; basis: string; reinsurer: string; ceded_sum_insured: string; premium: string; commission: string }[];
    placements: { id: string; reinsurer: string; slip_reference: string | null; share: string; ceded_sum_insured: string; premium: string; commission: string; placed_on: string }[];
    claims: { claim_id: string; claim_number: string; reinsurer: string; outstanding: string; recoverable: string }[];
    canPlace: boolean;
    reinsurers: { value: string; label: string }[];
    today: string;
}
