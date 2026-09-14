import type { Component } from 'vue';
import { Banknote, Calculator, FileType, House, BookOpen, CalendarCheck, ChartColumn, CheckCheck, FileText, Inbox, Landmark, ListChecks, Package, Percent, Receipt, Scale, ShieldAlert, Tags, Upload, Users, Wallet, BellRing, KeyRound, Network, Settings2, Target, UserCog, UsersRound, ClipboardCheck, Gauge, FileClock, RefreshCw, ListTree, BadgePercent, Grid3x3, BanknoteArrowDown, ShieldCheck, FileSpreadsheet, Sigma, IdCard, HandCoins, ReceiptText, SlidersHorizontal, Handshake, Share2, Truck, FileInput, Send, Armchair, TrendingDown, PiggyBank, Coins, ScrollText, Shapes } from 'lucide-vue-next';

/**
 * Sidebar navigation (UX brief §3). GA-37: each label is the title of the page it opens, in the words of docs/glossary.md, and no two items share an icon. Order is frequency of use, not the org chart. `any` mirrors the server's area permissions (a page opens
 * for users holding any of them; empty = every signed-in user), so links never lead to a 403. `badge` names the work-queue count the
 * server shares in `shell.badges`.
 *
 * Consistency pass: primary items run from the daily desk work (Approvals, quotes, policies, money in, claims, bank, money out) to the monthly work (commission,
 * payroll, ledger, close, reports, regulatory); the Assets & budgets group follows; secondary items run from registers used weekly (collections, suppliers, payslips,
 * distribution, reinsurance, regulatory detail) to setup and administration.
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
const people = ['hr.manage_employees', 'payroll.prepare', 'payroll.approve', 'payroll.pay', 'payroll.manage_rules']; // People and Payroll MVP
const payables = ['ap.manage_suppliers', 'ap.enter_bills', 'ap.approve_bills', 'ap.prepare_payments', 'ap.approve_payments', 'ap.release_payments', reader];
const claims = ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release', 'claim.close', reader];
const reinsurance = ['ri.view', 'ri.manage_treaties', 'ri.place_facultative'];
const regulatory = ['reports.regulatory', 'regulatory.file', 'provisions.run', 'provisions.approve'];
const assets = ['fa.manage', 'fa.post_depreciation', reader];

export const navigation: NavItem[] = [
    // Daily desk work.
    { id: 'home', page: 'home/Index', label: 'Home', href: '/home', icon: House, any: [] },
    { id: 'approvals', page: 'approvals/Index', label: 'Approvals', href: '/approvals', icon: CheckCheck, any: [], badge: 'approvals' },
    { id: 'quotes', detail: 'quotations/Workbench', page: 'quotations/Index', label: 'Quotes', href: '/quotations', icon: Calculator, any: ['quotation.create', 'policy.create'] },
    { id: 'referrals', detail: 'proposals/Show', page: 'underwriting/Referrals', label: 'Referrals', href: '/underwriting/referrals', icon: ClipboardCheck, any: ['underwriting.decide'] },
    { id: 'cover-notes', page: 'coverNotes/Index', label: 'Cover notes', href: '/cover-notes', icon: FileClock, any: ['cover_note.issue', 'cover_note.cancel', 'quotation.create'] },
    { id: 'policies', detail: 'policies/Show', page: 'policies/Index', label: 'Policies', href: '/policies', icon: FileText, any: ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', reader], badge: 'policies' },
    // Slice R9 (A-126): the expiry register queue, for whoever works renewals at the branch.
    { id: 'renewals', page: 'renewals/Index', label: 'Renewals', href: '/renewals', icon: RefreshCw, any: ['renewal.manage'] },
    { id: 'receipts', detail: 'receipts/Show', page: 'receipts/Index', label: 'Receipts', href: '/receipts', icon: Banknote, any: collections, badge: 'receipts' },
    { id: 'suspense', page: 'suspense/Index', label: 'Suspense', href: '/suspense', icon: Inbox, any: collections, badge: 'suspense' },
    { id: 'claims', detail: 'claims/Show', page: 'claims/Index', label: 'Claims', href: '/claims', icon: ShieldAlert, any: claims, badge: 'claims' },
    { id: 'bank', detail: 'bank/Show', page: 'bank/Index', label: 'Bank accounts', href: '/bank', icon: Landmark, any: ['bank.import', 'bank.match', 'bank.manage_accounts', reader], badge: 'bank' },
    // Slices 2.3/2.4 accounts payable.
    { id: 'supplier-bills', detail: 'payables/bills/Show', page: 'payables/bills/Index', label: 'Supplier bills', href: '/payables/bills', icon: FileInput, any: payables },
    { id: 'payment-runs', detail: 'payables/runs/Show', page: 'payables/runs/Index', label: 'Payment runs', href: '/payables/payment-runs', icon: Send, any: payables },
    // Monthly work.
    // GA-10: one monthly commission run is the only place commission is approved and paid; /commission is its read-only history, linked from there.
    { id: 'statement-run', detail: 'commission/Index', page: 'distribution/statements/Index', label: 'Commission statements', href: '/distribution/statements', icon: Percent, any: ['commission.approve', 'commission.pay', reader] },
    // People and Payroll MVP (addendum §B.9–§B.11).
    { id: 'payroll-runs', detail: 'people/payroll/Show', page: 'people/payroll/Index', label: 'Payroll runs', href: '/people/payroll', icon: HandCoins, any: people },
    { id: 'employees', detail: 'people/employees/Show', page: 'people/employees/Index', label: 'Employees', href: '/people/employees', icon: IdCard, any: people },
    { id: 'journals', detail: 'accounting/journals/Show', page: 'accounting/journals/Index', label: 'Journals', href: '/accounting/journals', icon: BookOpen, any: ['accounting.view_journals'], badge: 'journals' },
    { id: 'trial-balance', detail: 'reports/Show', page: 'accounting/TrialBalance', label: 'Trial balance', href: '/accounting/trial-balance', icon: Scale, any: [reader] },
    { id: 'close', detail: 'close/Run', page: 'close/Index', label: 'Month-end close', href: '/close', icon: CalendarCheck, any: ['periods.soft_lock', 'periods.lock', 'periods.reopen', reader], badge: 'close' },
    // GA-12: the claims desk opens the claims reports (outstanding claims, claims paid, loss ratio) with reports.claims.
    { id: 'reports', detail: 'reports/Show', page: 'reports/Index', label: 'Reports', href: '/reports', icon: ChartColumn, any: [reader, 'reports.claims'] },
    // Market gap G5: the Regulatory dashboard, returns and the quarterly technical provisions run.
    { id: 'regulatory', page: 'regulatory/Dashboard', label: 'Regulatory dashboard', href: '/regulatory', icon: ShieldCheck, any: regulatory },
    // Design addendum v2 §B.7: fixed assets (register, depreciation, classes) (secondary items like the other finance registers).
    { id: 'fixed-assets', detail: 'fixedAssets/Show', page: 'fixedAssets/Index', label: 'Fixed assets', href: '/fixed-assets', icon: Armchair, any: assets, secondary: true },
    { id: 'depreciation', page: 'fixedAssets/Depreciation', label: 'Monthly depreciation', href: '/fixed-assets/depreciation', icon: TrendingDown, any: assets, secondary: true },
    // Design addendum v2 §B.8.1: budgets and the variance report.
    { id: 'budgets', detail: 'budgets/Show', page: 'budgets/Index', label: 'Budgets', href: '/budgets', icon: PiggyBank, any: ['budget.prepare', 'budget.approve', reader], secondary: true },
    // Design addendum v2 §B.6: petty cash floats, vouchers, replenishment and counts.
    { id: 'petty-cash', detail: 'pettyCash/Show', page: 'pettyCash/Index', label: 'Petty cash', href: '/petty-cash', icon: Coins, any: ['pettycash.spend', 'pettycash.replenish', 'pettycash.approve', reader], secondary: true },
    // Secondary: registers used weekly.
    { id: 'parties', label: 'Parties', href: '/parties', icon: Users, any: ['party.manage', 'agent.manage', 'policy.create', reader], secondary: true },
    { id: 'refunds', label: 'Refunds', href: '/refunds', icon: BanknoteArrowDown, any: collections, secondary: true },
    { id: 'agent-cash', label: 'Agent cash', href: '/agent-cash', icon: Wallet, any: collections, secondary: true },
    { id: 'cheques', label: 'Cheque register', href: '/cheques', icon: Receipt, any: collections, secondary: true },
    { id: 'dunning', label: 'Payment reminders', href: '/dunning', icon: BellRing, any: collections, secondary: true },
    { id: 'suppliers', detail: 'payables/suppliers/Show', page: 'payables/suppliers/Index', label: 'Suppliers', href: '/payables/suppliers', icon: Truck, any: payables, secondary: true },
    { id: 'payslips', page: 'people/payslips/Index', label: 'Payslips', href: '/people/payslips', icon: ReceiptText, any: people, secondary: true },
    { id: 'producers', detail: 'distribution/producers/Show', page: 'distribution/producers/Index', label: 'Producers', href: '/distribution/producers', icon: UsersRound, any: ['agent.manage', 'commission.approve', 'commission.pay', 'commission.manage_plans', reader, 'reports.regulatory'], secondary: true },
    { id: 'hierarchy', page: 'distribution/hierarchy/Index', label: 'Hierarchy', href: '/distribution/hierarchy', icon: Network, any: ['agent.manage', 'commission.manage_plans', 'commission.approve', reader], secondary: true },
    { id: 'schemes', detail: 'distribution/schemes/Show', page: 'distribution/schemes/Index', label: 'Compensation schemes', href: '/distribution/schemes', icon: BadgePercent, any: ['commission.manage_plans', 'commission.approve', reader], secondary: true },
    { id: 'targets', page: 'distribution/targets/Index', label: 'Targets', href: '/distribution/targets', icon: Target, any: ['agent.manage', 'commission.approve', reader], secondary: true },
    // Reinsurance MVP (G4).
    { id: 'ri-treaties', detail: 'reinsurance/treaties/Show', page: 'reinsurance/treaties/Index', label: 'Treaties', href: '/reinsurance/treaties', icon: Handshake, any: reinsurance, secondary: true },
    { id: 'ri-cessions', page: 'reinsurance/cessions/Index', label: 'Cessions', href: '/reinsurance/cessions', icon: Share2, any: reinsurance, secondary: true },
    { id: 'ri-statements', detail: 'reinsurance/statements/Show', page: 'reinsurance/statements/Index', label: 'Reinsurer statements', href: '/reinsurance/statements', icon: FileSpreadsheet, any: reinsurance, secondary: true },
    { id: 'regulatory-returns', page: 'regulatory/Returns', label: 'Regulatory returns', href: '/regulatory/returns', icon: ScrollText, any: regulatory, secondary: true },
    { id: 'technical-provisions', page: 'regulatory/Provisions', label: 'Technical provisions', href: '/regulatory/provisions', icon: Sigma, any: ['reports.regulatory', 'provisions.run', 'provisions.approve'], secondary: true },
    // Secondary: setup and administration.
    { id: 'tariffs', detail: 'rating/plans/Show', page: 'rating/plans/Index', label: 'Tariffs', href: '/rating/plans', icon: Grid3x3, any: ['rating.manage_plans', 'rating.approve_plans'], secondary: true },
    { id: 'products', label: 'Products', href: '/products', icon: Package, any: ['product.manage', 'policy.create', reader], secondary: true },
    { id: 'payroll-settings', page: 'people/settings/Index', label: 'Payroll settings', href: '/people/payroll-settings', icon: SlidersHorizontal, any: people, secondary: true },
    { id: 'asset-classes', page: 'fixedAssets/Classes', label: 'Asset classes', href: '/fixed-assets/classes', icon: Shapes, any: assets, secondary: true },
    // UX U2: accounts are added and changed here; the page opens for accounting.view_journals or accounting.manage_coa.
    { id: 'chart-of-accounts', page: 'accounting/ChartOfAccounts', label: 'Chart of accounts', href: '/accounting/chart-of-accounts', icon: ListTree, any: ['accounting.view_journals', 'accounting.manage_coa'], secondary: true },
    { id: 'imports', label: 'Imports', href: '/accounting/imports', icon: Upload, any: ['accounting.view_journals'], secondary: true },
    { id: 'account-roles', page: 'accounting/AccountRoles', label: 'Account roles', href: '/accounting/account-roles', icon: Tags, any: ['accounting.manage_coa'], secondary: true },
    { id: 'setup', page: 'setup/Index', label: 'Setup', href: '/setup', icon: Settings2, any: ['platform.manage_roles', 'periods.lock', 'accounting.manage_coa', 'product.manage', 'platform.manage_users', 'platform.manage_approvals'], secondary: true },
    { id: 'users', detail: 'admin/users/Show', page: 'admin/users/Index', label: 'Users', href: '/admin/users', icon: UserCog, any: ['platform.manage_users'], secondary: true },
    { id: 'roles', detail: 'admin/roles/Show', page: 'admin/roles/Index', label: 'Roles', href: '/admin/roles', icon: KeyRound, any: ['platform.manage_roles'], secondary: true },
    { id: 'document-templates', detail: 'documents/templates/Edit', page: 'documents/templates/Index', label: 'Document templates', href: '/documents/templates', icon: FileType, any: ['document.manage_templates'], secondary: true },
    { id: 'underwriting-limits', page: 'admin/underwriting-limits/Index', label: 'Underwriting limits', href: '/admin/underwriting-limits', icon: Gauge, any: ['underwriting.manage_limits'], secondary: true },
    { id: 'approval-limits', page: 'admin/approval-limits/Index', label: 'Approval limits', href: '/admin/approval-limits', icon: ListChecks, any: ['platform.manage_approvals'], secondary: true },
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
