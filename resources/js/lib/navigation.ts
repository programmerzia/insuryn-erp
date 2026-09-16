import type { Component } from 'vue';
import { Banknote, Calculator, FileType, House, BookOpen, CalendarCheck, ChartColumn, CheckCheck, CircleAlert, FileText, Inbox, Landmark, ListChecks, Package, Percent, Receipt, Scale, ShieldAlert, Tags, Upload, Users, Wallet, BellRing, KeyRound, Network, Settings2, Target, UserCog, UsersRound, ClipboardCheck, Gauge, FileClock, RefreshCw, ListTree, BadgePercent, Grid3x3, BanknoteArrowDown, ShieldCheck, FileSpreadsheet, Sigma, IdCard, HandCoins, ReceiptText, SlidersHorizontal, Handshake, Share2, Truck, FileInput, Send, Armchair, TrendingDown, PiggyBank, Coins, ScrollText, Shapes, Globe } from 'lucide-vue-next';

/**
 * Sidebar navigation (UX brief §3). GA-37: each label is the title of the page it opens, in the words of docs/glossary.md, and no two items share an icon. `any` mirrors the server's area permissions (a page opens
 * for users holding any of them; empty = every signed-in user), so links never lead to a 403. `badge` names the work-queue count the
 * server shares in `shell.badges`.
 *
 * Items sit in named sections by area of work (`sections`, in sidebar order); inside a section they run from the daily queue to the setup register. The sidebar
 * shows each section as a collapsible heading (Home has none), hiding a section none of whose pages open for the user.
 */
export interface NavItem {
    id: string;
    label: string;
    href: string;
    icon: Component;
    any: string[];
    badge?: string;
    /** The `sections` entry the item is listed under. */
    section: SectionId;
    /** Inertia page components (the list and the record it opens), so their code can be fetched ahead of the first visit. */
    page?: string;
    detail?: string;
}

export type SectionId = 'home' | 'sales' | 'collections' | 'claims' | 'payables' | 'reinsurance' | 'people' | 'assets' | 'accounting' | 'regulatory' | 'admin';
export interface NavSection {
    id: SectionId;
    label: string;
    /** Open for a user who has never touched the section (a badge count > 0 opens it too). */
    open: boolean;
}

/** Sidebar order. Home has no heading; the first four are open by default, the monthly and setup areas closed. */
export const sections: NavSection[] = [
    { id: 'home', label: 'Home', open: true },
    { id: 'sales', label: 'Sales', open: true },
    { id: 'collections', label: 'Collections', open: true },
    { id: 'claims', label: 'Claims', open: true },
    { id: 'payables', label: 'Payables', open: false },
    { id: 'reinsurance', label: 'Reinsurance', open: false },
    { id: 'people', label: 'HR & Payroll', open: false },
    { id: 'assets', label: 'Assets & budgets', open: false },
    { id: 'accounting', label: 'Accounting', open: false },
    { id: 'regulatory', label: 'Regulatory', open: false },
    { id: 'admin', label: 'Admin', open: false },
];

const reader = 'reports.financial';
const collections = ['receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', reader];
const people = ['hr.manage_employees', 'payroll.prepare', 'payroll.approve', 'payroll.pay', 'payroll.manage_rules']; // People and Payroll MVP
const payables = ['ap.manage_suppliers', 'ap.enter_bills', 'ap.approve_bills', 'ap.prepare_payments', 'ap.approve_payments', 'ap.release_payments', reader];
const claims = ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release', 'claim.close', reader];
const reinsurance = ['ri.view', 'ri.manage_treaties', 'ri.place_facultative'];
const regulatory = ['reports.regulatory', 'regulatory.file', 'provisions.run', 'provisions.approve'];
const assets = ['fa.manage', 'fa.post_depreciation', reader];

export const navigation: NavItem[] = [
    { id: 'home', page: 'home/Index', label: 'Home', href: '/home', icon: House, section: 'home', any: [] },
    { id: 'approvals', page: 'approvals/Index', label: 'Approvals', href: '/approvals', icon: CheckCheck, section: 'home', any: [], badge: 'approvals' },
    { id: 'quotes', detail: 'quotations/Workbench', page: 'quotations/Index', label: 'Quotes', href: '/quotations', icon: Calculator, section: 'sales', any: ['quotation.create', 'policy.create'] },
    { id: 'referrals', detail: 'proposals/Show', page: 'underwriting/Referrals', label: 'Referrals', href: '/underwriting/referrals', icon: ClipboardCheck, section: 'sales', any: ['underwriting.decide'] },
    { id: 'cover-notes', page: 'coverNotes/Index', label: 'Cover notes', href: '/cover-notes', icon: FileClock, section: 'sales', any: ['cover_note.issue', 'cover_note.cancel', 'quotation.create'] },
    { id: 'policies', detail: 'policies/Show', page: 'policies/Index', label: 'Policies', href: '/policies', icon: FileText, section: 'sales', any: ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', reader], badge: 'policies' },
    { id: 'renewals', page: 'renewals/Index', label: 'Renewals', href: '/renewals', icon: RefreshCw, section: 'sales', any: ['renewal.manage'] },
    { id: 'parties', label: 'Parties', href: '/parties', icon: Users, section: 'sales', any: ['party.manage', 'agent.manage', 'policy.create', reader] },
    { id: 'producers', detail: 'distribution/producers/Show', page: 'distribution/producers/Index', label: 'Producers', href: '/distribution/producers', icon: UsersRound, section: 'sales', any: ['agent.manage', 'commission.approve', 'commission.pay', 'commission.manage_plans', reader, 'reports.regulatory'] },
    { id: 'hierarchy', page: 'distribution/hierarchy/Index', label: 'Hierarchy', href: '/distribution/hierarchy', icon: Network, section: 'sales', any: ['agent.manage', 'commission.manage_plans', 'commission.approve', reader] },
    { id: 'schemes', detail: 'distribution/schemes/Show', page: 'distribution/schemes/Index', label: 'Compensation schemes', href: '/distribution/schemes', icon: BadgePercent, section: 'sales', any: ['commission.manage_plans', 'commission.approve', reader] },
    { id: 'targets', page: 'distribution/targets/Index', label: 'Targets', href: '/distribution/targets', icon: Target, section: 'sales', any: ['agent.manage', 'commission.approve', reader] },
    { id: 'tariffs', detail: 'rating/plans/Show', page: 'rating/plans/Index', label: 'Tariffs', href: '/rating/plans', icon: Grid3x3, section: 'sales', any: ['rating.manage_plans', 'rating.approve_plans'] },
    { id: 'products', label: 'Products', href: '/products', icon: Package, section: 'sales', any: ['product.manage', 'policy.create', reader] },
    { id: 'receipts', detail: 'receipts/Show', page: 'receipts/Index', label: 'Receipts', href: '/receipts', icon: Banknote, section: 'collections', any: collections, badge: 'receipts' },
    { id: 'suspense', page: 'suspense/Index', label: 'Suspense', href: '/suspense', icon: Inbox, section: 'collections', any: collections, badge: 'suspense' },
    { id: 'bank', detail: 'bank/Show', page: 'bank/Index', label: 'Bank accounts', href: '/bank', icon: Landmark, section: 'collections', any: ['bank.import', 'bank.match', 'bank.manage_accounts', reader], badge: 'bank' },
    { id: 'cheques', label: 'Cheque register', href: '/cheques', icon: Receipt, section: 'collections', any: collections },
    { id: 'dunning', label: 'Payment reminders', href: '/dunning', icon: BellRing, section: 'collections', any: collections },
    { id: 'refunds', label: 'Refunds', href: '/refunds', icon: BanknoteArrowDown, section: 'collections', any: collections },
    { id: 'agent-cash', label: 'Agent cash', href: '/agent-cash', icon: Wallet, section: 'collections', any: collections },
    { id: 'statement-run', detail: 'commission/Index', page: 'distribution/statements/Index', label: 'Commission statements', href: '/distribution/statements', icon: Percent, section: 'collections', any: ['commission.approve', 'commission.pay', reader] },
    { id: 'claims', detail: 'claims/Show', page: 'claims/Index', label: 'Claims', href: '/claims', icon: ShieldAlert, section: 'claims', any: claims, badge: 'claims' },
    { id: 'suppliers', detail: 'payables/suppliers/Show', page: 'payables/suppliers/Index', label: 'Suppliers', href: '/payables/suppliers', icon: Truck, section: 'payables', any: payables },
    { id: 'supplier-bills', detail: 'payables/bills/Show', page: 'payables/bills/Index', label: 'Supplier bills', href: '/payables/bills', icon: FileInput, section: 'payables', any: payables },
    { id: 'payment-runs', detail: 'payables/runs/Show', page: 'payables/runs/Index', label: 'Payment runs', href: '/payables/payment-runs', icon: Send, section: 'payables', any: payables },
    { id: 'ri-treaties', detail: 'reinsurance/treaties/Show', page: 'reinsurance/treaties/Index', label: 'Treaties', href: '/reinsurance/treaties', icon: Handshake, section: 'reinsurance', any: reinsurance },
    { id: 'ri-cessions', page: 'reinsurance/cessions/Index', label: 'Cessions', href: '/reinsurance/cessions', icon: Share2, section: 'reinsurance', any: reinsurance },
    { id: 'ri-statements', detail: 'reinsurance/statements/Show', page: 'reinsurance/statements/Index', label: 'Reinsurer statements', href: '/reinsurance/statements', icon: FileSpreadsheet, section: 'reinsurance', any: reinsurance },
    { id: 'employees', detail: 'people/employees/Show', page: 'people/employees/Index', label: 'Employees', href: '/people/employees', icon: IdCard, section: 'people', any: people },
    { id: 'payroll-runs', detail: 'people/payroll/Show', page: 'people/payroll/Index', label: 'Payroll runs', href: '/people/payroll', icon: HandCoins, section: 'people', any: people },
    { id: 'payslips', page: 'people/payslips/Index', label: 'Payslips', href: '/people/payslips', icon: ReceiptText, section: 'people', any: people },
    { id: 'payroll-settings', page: 'people/settings/Index', label: 'Payroll settings', href: '/people/payroll-settings', icon: SlidersHorizontal, section: 'people', any: people },
    { id: 'fixed-assets', detail: 'fixedAssets/Show', page: 'fixedAssets/Index', label: 'Fixed assets', href: '/fixed-assets', icon: Armchair, section: 'assets', any: assets },
    { id: 'depreciation', page: 'fixedAssets/Depreciation', label: 'Monthly depreciation', href: '/fixed-assets/depreciation', icon: TrendingDown, section: 'assets', any: assets },
    { id: 'asset-classes', page: 'fixedAssets/Classes', label: 'Asset classes', href: '/fixed-assets/classes', icon: Shapes, section: 'assets', any: assets },
    { id: 'budgets', detail: 'budgets/Show', page: 'budgets/Index', label: 'Budgets', href: '/budgets', icon: PiggyBank, section: 'assets', any: ['budget.prepare', 'budget.approve', reader] },
    { id: 'petty-cash', detail: 'pettyCash/Show', page: 'pettyCash/Index', label: 'Petty cash', href: '/petty-cash', icon: Coins, section: 'assets', any: ['pettycash.spend', 'pettycash.replenish', 'pettycash.approve', reader] },
    { id: 'journals', detail: 'accounting/journals/Show', page: 'accounting/journals/Index', label: 'Journals', href: '/accounting/journals', icon: BookOpen, section: 'accounting', any: ['accounting.view_journals'], badge: 'journals' },
    { id: 'accounting-events', page: 'accounting/events/Index', label: 'Accounting events', href: '/accounting/events', icon: CircleAlert, section: 'accounting', any: ['accounting.view_journals'], badge: 'failed_events' },
    { id: 'ledger-api', page: 'accounting/LedgerApiDemo', label: 'Ledger API demo', href: '/accounting/ledger-api', icon: Globe, section: 'accounting', any: ['accounting.view_journals'] },
    { id: 'chart-of-accounts', page: 'accounting/ChartOfAccounts', label: 'Chart of accounts', href: '/accounting/chart-of-accounts', icon: ListTree, section: 'accounting', any: ['accounting.view_journals', 'accounting.manage_coa'] },
    { id: 'account-roles', page: 'accounting/AccountRoles', label: 'Account roles', href: '/accounting/account-roles', icon: Tags, section: 'accounting', any: ['accounting.manage_coa'] },
    { id: 'imports', label: 'Imports', href: '/accounting/imports', icon: Upload, section: 'accounting', any: ['accounting.view_journals'] },
    { id: 'trial-balance', detail: 'reports/Show', page: 'accounting/TrialBalance', label: 'Trial balance', href: '/accounting/trial-balance', icon: Scale, section: 'accounting', any: [reader] },
    { id: 'close', detail: 'close/Run', page: 'close/Index', label: 'Month-end close', href: '/close', icon: CalendarCheck, section: 'accounting', any: ['periods.soft_lock', 'periods.lock', 'periods.reopen', reader], badge: 'close' },
    { id: 'reports', detail: 'reports/Show', page: 'reports/Index', label: 'Reports', href: '/reports', icon: ChartColumn, section: 'accounting', any: [reader, 'reports.claims'] },
    { id: 'regulatory', page: 'regulatory/Dashboard', label: 'Regulatory dashboard', href: '/regulatory', icon: ShieldCheck, section: 'regulatory', any: regulatory },
    { id: 'regulatory-returns', page: 'regulatory/Returns', label: 'Regulatory returns', href: '/regulatory/returns', icon: ScrollText, section: 'regulatory', any: regulatory },
    { id: 'technical-provisions', page: 'regulatory/Provisions', label: 'Technical provisions', href: '/regulatory/provisions', icon: Sigma, section: 'regulatory', any: ['reports.regulatory', 'provisions.run', 'provisions.approve'] },
    { id: 'users', detail: 'admin/users/Show', page: 'admin/users/Index', label: 'Users', href: '/admin/users', icon: UserCog, section: 'admin', any: ['platform.manage_users'] },
    { id: 'roles', detail: 'admin/roles/Show', page: 'admin/roles/Index', label: 'Roles', href: '/admin/roles', icon: KeyRound, section: 'admin', any: ['platform.manage_roles'] },
    { id: 'approval-limits', page: 'admin/approval-limits/Index', label: 'Approval limits', href: '/admin/approval-limits', icon: ListChecks, section: 'admin', any: ['platform.manage_approvals'] },
    { id: 'underwriting-limits', page: 'admin/underwriting-limits/Index', label: 'Underwriting limits', href: '/admin/underwriting-limits', icon: Gauge, section: 'admin', any: ['underwriting.manage_limits'] },
    { id: 'setup', page: 'setup/Index', label: 'Setup', href: '/setup', icon: Settings2, section: 'admin', any: ['platform.manage_roles', 'periods.lock', 'accounting.manage_coa', 'product.manage', 'platform.manage_users', 'platform.manage_approvals'] },
    { id: 'document-templates', detail: 'documents/templates/Edit', page: 'documents/templates/Index', label: 'Document templates', href: '/documents/templates', icon: FileType, section: 'admin', any: ['document.manage_templates'] },
];

/** Sidebar sections kept in accounting focus mode (demo / integration pitch). */
export const ACCOUNTING_FOCUS_SECTIONS: readonly SectionId[] = ['accounting', 'assets', 'payables'];

/** Finance pages listed under other sections but shown in accounting focus mode. */
export const ACCOUNTING_FOCUS_ITEM_IDS: readonly string[] = ['bank'];

export const ACCOUNTING_FOCUS_LANDING = '/accounting/ledger-api';

export function visibleNavigation(permissions: string[]): NavItem[] {
    const held = new Set(permissions);
    return navigation.filter((item) => item.any.length === 0 || item.any.some((permission) => held.has(permission)));
}

/** Hides insurance operations menus; keeps accounting, assets, budgets, payables and bank. */
export function filterNavigationFocus(items: NavItem[], accountingFocus: boolean): NavItem[] {
    if (!accountingFocus) {
        return items;
    }
    const sections = new Set(ACCOUNTING_FOCUS_SECTIONS);
    const extras = new Set(ACCOUNTING_FOCUS_ITEM_IDS);
    return items.filter((item) => sections.has(item.section) || extras.has(item.id));
}

/** The navigation item a URL belongs to: the longest matching href prefix. */
export function activeItem(items: NavItem[], url: string): NavItem | undefined {
    const path = url.split('?')[0] ?? url;
    return items.filter((item) => path === item.href || path.startsWith(`${item.href}/`)).sort((a, b) => b.href.length - a.href.length)[0];
}
