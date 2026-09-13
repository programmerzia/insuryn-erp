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
        label: 'Accounting',
        items: [
            { label: 'Journals', href: '/accounting/journals', any: ['accounting.view_journals'] },
            { label: 'Trial balance', href: '/accounting/trial-balance', any: [reader] },
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
