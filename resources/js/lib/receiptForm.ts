/**
 * Flow fix X1: where a new receipt starts. Opened from a policy, the server's prefill decides the amount, the allocation lines and the branch; otherwise
 * the user's branch (or the first branch when nothing decides it) and today. The channel is the user's last one either way.
 */
export interface ReceiptPrefill {
    policy: { id: string; number: string };
    amount: string;
    branch_id: string;
    allocations: { installment_id: string; label: string; amount: string; outstanding: string }[];
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
    branch_id: string; channel: string; amount: string; value_date: string; allocations: ReceiptAllocationLine[];
} {
    const branch = prefill?.branch_id ?? defaults.branch_id;
    return {
        branch_id: branch && branches.some((b) => b.id === branch) ? branch : (branches[0]?.id ?? ''),
        channel: defaults.channel,
        amount: prefill?.amount ?? '',
        value_date: defaults.value_date,
        allocations: (prefill?.allocations ?? []).map((line) => ({ installment_id: line.installment_id, amount: line.amount, outstanding: line.outstanding, label: line.label })),
    };
}
