import type { Component } from 'vue';
import { Banknote, House, BookOpen, CalendarCheck, ChartColumn, CheckCheck, FileText, Inbox, Landmark, Package, Percent, Receipt, Scale, ShieldAlert, Undo2, Upload, UserCheck, Users, Wallet, BellRing } from 'lucide-vue-next';

/**
 * Sidebar navigation (UX brief §3). Order is frequency of use, not the org chart. `any` mirrors the server's area permissions (a page opens
 * for users holding any of them; empty = every signed-in user), so links never lead to a 403. `badge` names the work-queue count the
 * server shares in `shell.badges`.
 */
export interface NavItem {
    id: string;
    label: string;
    href: string;
    icon: Component;
    any: string[];
    badge?: string;
    secondary?: boolean;
    /** Inertia page components (the list and the record it opens), so their code can be fetched ahead of the first visit. */
    page?: string;
    detail?: string;
}

const reader = 'reports.financial';
const collections = ['receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', reader];
const claims = ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release', 'claim.close', reader];

export const navigation: NavItem[] = [
    { id: 'home', page: 'home/Index', label: 'Home', href: '/home', icon: House, any: [] },
    { id: 'policies', detail: 'policies/Show', page: 'policies/Index', label: 'Policies', href: '/policies', icon: FileText, any: ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', reader], badge: 'policies' },
    { id: 'receipts', detail: 'receipts/Show', page: 'receipts/Index', label: 'Receipts', href: '/receipts', icon: Banknote, any: collections, badge: 'receipts' },
    { id: 'suspense', page: 'suspense/Index', label: 'Suspense', href: '/suspense', icon: Inbox, any: collections, badge: 'suspense' },
    { id: 'claims', detail: 'claims/Show', page: 'claims/Index', label: 'Claims', href: '/claims', icon: ShieldAlert, any: claims, badge: 'claims' },
    { id: 'bank', detail: 'bank/Show', page: 'bank/Index', label: 'Bank', href: '/bank', icon: Landmark, any: ['bank.import', 'bank.match', 'bank.manage_accounts', reader], badge: 'bank' },
    { id: 'approvals', page: 'approvals/Index', label: 'Approvals', href: '/approvals', icon: CheckCheck, any: [], badge: 'approvals' },
    { id: 'commission', page: 'commission/Index', label: 'Commission', href: '/commission', icon: Percent, any: ['commission.manage_plans', 'commission.approve', 'commission.pay', reader], badge: 'commission' },
    { id: 'journals', detail: 'accounting/journals/Show', page: 'accounting/journals/Index', label: 'Journals', href: '/accounting/journals', icon: BookOpen, any: ['accounting.view_journals'], badge: 'journals' },
    { id: 'trial-balance', detail: 'reports/Show', page: 'accounting/TrialBalance', label: 'Trial balance', href: '/accounting/trial-balance', icon: Scale, any: [reader] },
    { id: 'close', detail: 'close/Run', page: 'close/Index', label: 'Close', href: '/close', icon: CalendarCheck, any: ['periods.soft_lock', 'periods.lock', 'periods.reopen', reader], badge: 'close' },
    { id: 'reports', detail: 'reports/Show', page: 'reports/Index', label: 'Reports', href: '/reports', icon: ChartColumn, any: [reader] },
    { id: 'parties', label: 'Parties', href: '/parties', icon: Users, any: ['party.manage', 'agent.manage', 'policy.create', reader], secondary: true },
    { id: 'agents', label: 'Agents', href: '/agents', icon: UserCheck, any: ['party.manage', 'agent.manage', 'policy.create', reader], secondary: true },
    { id: 'products', label: 'Products', href: '/products', icon: Package, any: ['product.manage', 'policy.create', reader], secondary: true },
    { id: 'refunds', label: 'Refunds', href: '/refunds', icon: Undo2, any: collections, secondary: true },
    { id: 'agent-cash', label: 'Agent cash', href: '/agent-cash', icon: Wallet, any: collections, secondary: true },
    { id: 'cheques', label: 'Cheques', href: '/cheques', icon: Receipt, any: collections, secondary: true },
    { id: 'dunning', label: 'Reminders', href: '/dunning', icon: BellRing, any: collections, secondary: true },
    { id: 'imports', label: 'Imports', href: '/accounting/imports', icon: Upload, any: ['accounting.view_journals'], secondary: true },
];

export function visibleNavigation(permissions: string[]): NavItem[] {
    const held = new Set(permissions);
    return navigation.filter((item) => item.any.length === 0 || item.any.some((permission) => held.has(permission)));
}

/** The navigation item a URL belongs to: the longest matching href prefix. */
export function activeItem(items: NavItem[], url: string): NavItem | undefined {
    const path = url.split('?')[0] ?? url;
    return items.filter((item) => path === item.href || path.startsWith(`${item.href}/`)).sort((a, b) => b.href.length - a.href.length)[0];
}
