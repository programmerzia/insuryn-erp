/**
 * Gap fix GA-19: starting values for money drawers that record something happening now. Pure functions, no Vue, so the defaults are tested once.
 */

export interface AgentCashRow {
    agent_id: string;
    agent_code: string;
    undeposited: string;
}

/** Record a deposit: the agent's cash not yet deposited (empty when none is held or no agent is chosen), deposited today. */
export function depositDraft(rows: AgentCashRow[], agentId: string, today: string): { agent_id: string; amount: string; deposited_on: string } {
    const row = rows.find((r) => r.agent_id === agentId);
    const held = row && row.undeposited !== '0.00' && !row.undeposited.startsWith('-') ? row.undeposited : '';
    return { agent_id: agentId, amount: held, deposited_on: today };
}

/** Request a refund: what is still refundable on the chosen cancelled policy (the whole amount by default; the requester may lower it). */
export function refundAmount(refundable: { policy_id: string; available: string }[], policyId: string): string {
    return refundable.find((r) => r.policy_id === policyId)?.available ?? '';
}

/** A date inside [from, to]: today unless today falls outside, then the nearest end (an endorsement or cancellation inside the cover). */
export function dateWithin(today: string, from: string | null | undefined, to: string | null | undefined): string {
    if (from && today < from) return from;
    if (to && today > to) return to;
    return today;
}
