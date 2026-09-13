/** Top bar navigation. `any` mirrors the server's area permissions (a page opens for users holding any of them), so links never lead to a 403. */
export interface NavItem {
    label: string;
    href: string;
    any: string[];
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

const reader = 'reports.financial';
const collections = ['receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', reader];

export const navigation: NavGroup[] = [
    {
        label: 'Operations',
        items: [
            { label: 'Parties', href: '/parties', any: ['party.manage', 'agent.manage', 'policy.create', reader] },
            { label: 'Agents', href: '/agents', any: ['party.manage', 'agent.manage', 'policy.create', reader] },
            { label: 'Products', href: '/products', any: ['product.manage', 'policy.create', reader] },
            { label: 'Policies', href: '/policies', any: ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', reader] },
        ],
    },
    {
        label: 'Claims',
        items: [
            { label: 'Claims', href: '/claims', any: ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release', 'claim.close', reader] },
            { label: 'Commission', href: '/commission', any: ['commission.manage_plans', 'commission.approve', 'commission.pay', reader] },
        ],
    },
    {
        label: 'Collections',
        items: [
            { label: 'Receipts', href: '/receipts', any: collections },
            { label: 'Suspense', href: '/suspense', any: collections },
            { label: 'Refunds', href: '/refunds', any: collections },
            { label: 'Agent cash', href: '/agent-cash', any: collections },
            { label: 'Dunning', href: '/dunning', any: collections },
            { label: 'Bank', href: '/bank', any: ['bank.import', 'bank.match', 'bank.manage_accounts', reader] },
        ],
    },
    {
        label: 'Accounting',
        items: [
            { label: 'Journals', href: '/accounting/journals', any: ['accounting.view_journals'] },
            { label: 'Trial balance', href: '/accounting/trial-balance', any: [reader] },
            { label: 'Close', href: '/close', any: ['periods.soft_lock', 'periods.lock', 'periods.reopen', reader] },
            { label: 'Reports', href: '/reports', any: [reader] },
            { label: 'Imports', href: '/accounting/imports', any: ['accounting.view_journals'] },
        ],
    },
];

export function visibleNavigation(permissions: string[]): NavGroup[] {
    const held = new Set(permissions);
    return navigation
        .map((group) => ({ ...group, items: group.items.filter((item) => item.any.some((permission) => held.has(permission))) }))
        .filter((group) => group.items.length > 0);
}
