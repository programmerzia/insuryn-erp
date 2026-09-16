import type { Component } from 'vue';
import { ArrowRight, Keyboard, Monitor, Moon, PanelLeft, Plus, Rows3, Sun, Upload } from 'lucide-vue-next';
import { fuzzyScore } from '@/lib/fuzzy';
import { filterNavigationFocus, navigation } from '@/lib/navigation';
import type { Preferences, Recent } from '@/lib/preferences';
import type { ShortcutId } from '@/lib/shortcuts';

/** Command palette entries (UX brief §4): navigate, act, change settings. Records come from GET /search. */
export interface Command {
    id: string;
    group: 'Go to' | 'Actions' | 'Settings';
    label: string;
    icon: Component;
    keywords?: string;
    href?: string;
    shortcut?: ShortcutId;
    preference?: { key: keyof Preferences; value: unknown };
    special?: 'toggle-sidebar' | 'shortcuts';
}

const actions: (Omit<Command, 'group' | 'icon'> & { any: string[]; icon?: Component })[] = [
    // GA-11: one quote path, the rated quote workbench (the typed-premium form stays on the Policies list while unrated products exist).
    { id: 'new-quote', label: 'New quote', href: '/quotations/create', keywords: 'quotation policy issue create price', any: ['quotation.create'] },
    { id: 'new-receipt', label: 'Record a receipt', href: '/receipts/create', keywords: 'new receipt payment money received', any: ['receipt.create'] },
    { id: 'new-claim', label: 'Register a claim', href: '/claims/create', keywords: 'new claim loss', any: ['claim.register'] },
    { id: 'new-journal', label: 'New manual journal', href: '/accounting/journals/create', keywords: 'adjustment accrual', any: ['accounting.create_manual_journal'] },
    { id: 'import-statement', label: 'Import a bank statement', href: '/bank', keywords: 'bank csv match', any: ['bank.import'], icon: Upload },
    { id: 'import-coa', label: 'Import chart of accounts or opening balances', href: '/accounting/imports', keywords: 'coa opening', any: ['accounting.view_journals'], icon: Upload },
    { id: 'start-close', label: 'Start month-end close', href: '/close', keywords: 'lock period close month', any: ['periods.soft_lock', 'periods.lock'] },
    { id: 'new-supplier-bill', label: 'Enter a supplier bill', href: '/payables/bills/create', keywords: 'payables invoice vendor expense ap', any: ['ap.enter_bills'] },
    { id: 'new-payment-run', label: 'New payment run', href: '/payables/payment-runs/create', keywords: 'pay suppliers bills due bank file ap', any: ['ap.prepare_payments'] },
    { id: 'post-depreciation', label: 'Post monthly depreciation', href: '/fixed-assets/depreciation', keywords: 'fixed assets depreciate month batch', any: ['fa.post_depreciation'] },
    { id: 'petty-cash-voucher', label: 'Pay a petty cash voucher', href: '/petty-cash', keywords: 'float expense small cash custodian', any: ['pettycash.spend'] },
    { id: 'pay-commission', label: 'Approve or pay commission', href: '/distribution/statements', keywords: 'agent producer payout statement run', any: ['commission.approve', 'commission.pay'] },
    // Consistency pass: the main actions of payables, reinsurance, regulatory, people, fixed assets, budgets and petty cash, gated like the pages.
    { id: 'new-bill', label: 'Enter a supplier bill', href: '/payables/bills/create', keywords: 'new bill invoice vendor supplier payable expense', any: ['ap.enter_bills'] },
    { id: 'new-payment-run', label: 'New payment run', href: '/payables/payment-runs/create', keywords: 'pay suppliers bills bank transfer', any: ['ap.prepare_payments'] },
    { id: 'new-treaty', label: 'New treaty', href: '/reinsurance/treaties/create', keywords: 'reinsurance quota share surplus excess of loss', any: ['ri.manage_treaties'] },
    { id: 'run-provisions', label: 'Run technical provisions', href: '/regulatory/provisions', keywords: 'ibnr upr outstanding claims reserve quarter regulatory', any: ['provisions.run'] },
    { id: 'generate-returns', label: 'Generate regulatory returns', href: '/regulatory/returns', keywords: 'idra return filing regulator', any: ['reports.regulatory'] },
    { id: 'hire-employee', label: 'Hire employee', href: '/people/employees', keywords: 'new employee staff hr joiner', any: ['hr.manage_employees'] },
    { id: 'calculate-payroll', label: 'Calculate payroll', href: '/people/payroll', keywords: 'salary payroll run month payslips', any: ['payroll.prepare'] },
    { id: 'post-depreciation', label: 'Post monthly depreciation', href: '/fixed-assets/depreciation', keywords: 'fixed assets depreciation month', any: ['fa.post_depreciation'] },
    { id: 'new-asset', label: 'Capitalise an asset', href: '/fixed-assets', keywords: 'new fixed asset add capitalize', any: ['fa.manage'] },
    { id: 'new-budget', label: 'New budget', href: '/budgets', keywords: 'budget version year plan', any: ['budget.prepare'] },
    { id: 'petty-cash-voucher', label: 'Record a petty cash voucher', href: '/petty-cash', keywords: 'petty cash spend expense float voucher', any: ['pettycash.spend'] },
    { id: 'prepare-ri-statement', label: 'Prepare a reinsurer statement', href: '/reinsurance/statements', keywords: 'reinsurance quarter account bordereau', any: ['ri.manage_treaties'] },
];

const settings: Command[] = [
    { id: 'theme-system', group: 'Settings', label: 'Theme: match the system', icon: Monitor, preference: { key: 'theme', value: 'system' } },
    { id: 'theme-light', group: 'Settings', label: 'Theme: light', icon: Sun, preference: { key: 'theme', value: 'light' }, shortcut: 'app.theme' },
    { id: 'theme-dark', group: 'Settings', label: 'Theme: dark', icon: Moon, preference: { key: 'theme', value: 'dark' }, shortcut: 'app.theme' },
    { id: 'density-compact', group: 'Settings', label: 'Rows: compact', icon: Rows3, preference: { key: 'density', value: 'compact' }, shortcut: 'app.density' },
    { id: 'density-comfortable', group: 'Settings', label: 'Rows: comfortable', icon: Rows3, preference: { key: 'density', value: 'comfortable' }, shortcut: 'app.density' },
    { id: 'sidebar', group: 'Settings', label: 'Collapse or expand the sidebar', icon: PanelLeft, special: 'toggle-sidebar', shortcut: 'app.sidebar' },
    { id: 'shortcuts', group: 'Settings', label: 'Show keyboard shortcuts', icon: Keyboard, special: 'shortcuts', keywords: 'keys help' },
];

const ACCOUNTING_FOCUS_ACTION_IDS = new Set([
    'new-journal', 'import-coa', 'import-statement', 'start-close', 'new-supplier-bill', 'new-payment-run', 'new-bill', 'post-depreciation', 'new-asset', 'new-budget', 'petty-cash-voucher',
]);

export function buildCommands(permissions: string[], accountingFocus = false): Command[] {
    const held = new Set(permissions);
    const allowed = (any: string[]) => any.length === 0 || any.some((p) => held.has(p));
    const nav = filterNavigationFocus(navigation.filter((item) => allowed(item.any)), accountingFocus);
    const actionList = actions.filter((action) => allowed(action.any) && (!accountingFocus || ACCOUNTING_FOCUS_ACTION_IDS.has(action.id)));
    return [
        ...nav.map((item): Command => ({ id: `go-${item.id}`, group: 'Go to', label: `Go to ${item.label.toLowerCase()}`, icon: item.icon, href: item.href })),
        ...actionList.map(({ any: _any, icon, ...action }): Command => ({ ...action, group: 'Actions', icon: icon ?? Plus })),
        ...settings,
    ];
}

/** Recent commands first when the query is empty; otherwise best fuzzy match first, recents breaking ties. */
export function rankCommands(commands: Command[], query: string, recents: Recent[]): Command[] {
    const recentIndex = new Map(recents.map((r, i) => [r.href, i]));
    const scored = commands
        .map((command) => {
            const score = Math.max(fuzzyScore(query, command.label), fuzzyScore(query, command.keywords ?? '') * 0.5);
            const recent = command.href !== undefined ? recentIndex.get(command.href) : undefined;
            return { command, score, recent: recent === undefined ? Number.POSITIVE_INFINITY : recent };
        })
        .filter((entry) => entry.score > 0);
    if (query.trim() === '') {
        const groupOrder = { Actions: 0, 'Go to': 1, Settings: 2 } as const;
        return scored.sort((a, b) => a.recent - b.recent || groupOrder[a.command.group] - groupOrder[b.command.group]).map((entry) => entry.command);
    }
    return scored.sort((a, b) => b.score - a.score || a.recent - b.recent).map((entry) => entry.command);
}

export function rememberRecent(recents: Recent[], item: Recent): Recent[] {
    return [item, ...recents.filter((r) => r.href !== item.href)].slice(0, 20);
}

export { ArrowRight };
