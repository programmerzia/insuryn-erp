/**
 * Flow fix X1: where a new receipt starts. Opened from a policy, the server's prefill decides the amount, the allocation lines and the branch; otherwise
 * the user's branch (or the first branch when nothing decides it) and today. GA-38: the payer ("Received from") is the policyholder, and the channel
 * starts as that payer's last channel, otherwise the default (bank transfer).
 */
export interface ReceiptPrefill {
    policy: { id: string; number: string };
    amount: string;
    branch_id: string;
    allocations: { installment_id: string; label: string; amount: string; outstanding: string }[];
    payer?: { id: string; label: string } | null;
    channel?: string | null;
}
export interface ReceiptDefaults {
    branch_id: string | null;
    value_date: string;
    channel: string;
}
export interface ReceiptAllocationLine {
    installment_id: string;
    amount: string;
    outstanding?: string;
    label?: string;
}

export function initialReceipt(prefill: ReceiptPrefill | null | undefined, defaults: ReceiptDefaults, branches: { id: string }[]): {
    branch_id: string; party_id: string; channel: string; amount: string; value_date: string; allocations: ReceiptAllocationLine[];
} {
    const branch = prefill?.branch_id ?? defaults.branch_id;
    return {
        branch_id: branch && branches.some((b) => b.id === branch) ? branch : (branches[0]?.id ?? ''),
        party_id: prefill?.payer?.id ?? '',
        channel: prefill?.channel ?? defaults.channel,
        amount: prefill?.amount ?? '',
        value_date: defaults.value_date,
        allocations: (prefill?.allocations ?? []).map((line) => ({ installment_id: line.installment_id, amount: line.amount, outstanding: line.outstanding, label: line.label })),
    };
}

/** GA-03 (D-65): whether the receipt form allocates in this branch. Without the list the form allocates, as before. */
export function receiptAllocates(allocateBranchIds: string[] | undefined, branchId: string): boolean {
    return allocateBranchIds === undefined || allocateBranchIds.includes(branchId);
}

/**
 * What the receipt form posts. Someone who does not allocate in the branch sends no allocation lines; opened from a policy, the receipt notes that
 * policy (`for_policy_id`) so the money waits in suspense marked for it.
 */
export function receiptPayload<T extends { allocations: ReceiptAllocationLine[] }>(data: T, allocates: boolean, policyId: string | null): Omit<T, 'allocations'> & {
    allocations: { installment_id: string; amount: string }[]; for_policy_id: string | null;
} {
    return {
        ...data,
        allocations: allocates ? data.allocations.map(({ installment_id, amount }) => ({ installment_id, amount })) : [],
        for_policy_id: policyId,
    };
}
