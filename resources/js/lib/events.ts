/** Accounting event types in plain words for previews and timelines (users act on policies and receipts, not on event codes — spec §9). */
const LABELS: Record<string, string> = {
    POLICY_ISSUED: 'Policy issued', POLICY_ENDORSED: 'Policy endorsed', POLICY_CANCELLED: 'Policy cancelled', PREMIUM_EARNED: 'Premium earned',
    PREMIUM_RECEIVED: 'Premium received', RECEIPT_RECORDED: 'Money held in suspense', RECEIPT_ALLOCATED: 'Suspense allocated',
    PREMIUM_RECEIPT_REVERSED: 'Premium receipt reversed', RECEIPT_ALLOCATION_REVERSED: 'Allocation reversed', RECEIPT_BOUNCED: 'Cheque bounced',
    AGENT_CASH_COLLECTED: 'Cash collected by agent', AGENT_DEPOSIT_RECORDED: 'Agent deposit', REFUND_ISSUED: 'Refund paid', PREMIUM_WRITTEN_OFF: 'Premium written off',
    COMMISSION_EARNED: 'Commission earned', COMMISSION_CLAWBACK: 'Commission clawed back', COMMISSION_PAID: 'Commission paid',
    CLAIM_RESERVED: 'Claim reserve set', CLAIM_RESERVE_ADJUSTED: 'Claim reserve changed', CLAIM_APPROVED: 'Claim payment approved',
    CLAIM_PAID: 'Claim paid', CLAIM_RECOVERED: 'Recovery received', CLAIM_CLOSED: 'Claim closed', PAYROLL_POSTED: 'Payroll posted', MANUAL_JOURNAL: 'Manual journal', REVERSAL: 'Reversal', YEAR_END_CLOSE: 'Year-end close',
    // Gap fix GA-14.
    CHEQUE_CLEARED: 'Cheque cleared', CHEQUE_RETURN_CHARGED: 'Bank charge for a returned cheque',
    // Market gap G5: technical provisions.
    IBNR_PROVISION: 'IBNR provision set', IBNR_PROVISION_REVERSED: 'Prior quarter IBNR released',
};

/** Gap audit GA-34: whether a code is a known event type, so a report cell like the branch code "HO" is not turned into "Ho". */
export function isEventType(value: string): boolean {
    return Object.hasOwn(LABELS, value);
}

export function eventLabel(type: string): string {
    return LABELS[type] ?? type.toLowerCase().replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
}
