/** Gap fix GA-04: what the approvals inbox shows the approver before deciding (ApprovalInboxQuery::preview). */
export interface ApprovalPreview {
    link_label: string;
    details: { label: string; value: string; date?: boolean }[];
    lines: { account: string; name: string; debit: string | null; credit: string | null }[];
    posts_on_final_step: boolean;
}

/** "Step 1 of 2", or "Step 1" when the number of steps is not known. */
export function stepLabel(step: number, total?: number): string {
    return total && total > 1 ? `Step ${step} of ${total}` : total === 1 ? 'Only step' : `Step ${step}`;
}

/** What approving does, said before the approver decides. */
export function approvalOutcome(finalStep: boolean, postsOnFinalStep: boolean): string {
    if (!finalStep) return 'Approving passes it to the next approver; nothing is posted yet.';
    return postsOnFinalStep ? 'Yours is the last approval: approving posts the entries above to the ledger, and you see them again before confirming.' : 'Yours is the last approval.';
}
