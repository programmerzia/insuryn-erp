import type { Component } from 'vue';
import { ArrowRight, Keyboard, Monitor, Moon, PanelLeft, Plus, Rows3, Sun, Upload } from 'lucide-vue-next';
import { fuzzyScore } from '@/lib/fuzzy';
import { navigation } from '@/lib/navigation';
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
    { id: 'new-quote', label: 'New quote', href: '/policies/create', keywords: 'policy issue create', any: ['policy.create'] },
    { id: 'new-receipt', label: 'Record a receipt', href: '/receipts/create', keywords: 'new receipt payment money received', any: ['receipt.create'] },
    { id: 'new-claim', label: 'Register a claim', href: '/claims/create', keywords: 'new claim loss', any: ['claim.register'] },
    { id: 'new-journal', label: 'New manual journal', href: '/accounting/journals/create', keywords: 'adjustment accrual', any: ['accounting.create_manual_journal'] },
    { id: 'import-statement', label: 'Import a bank statement', href: '/bank', keywords: 'bank csv match', any: ['bank.import'], icon: Upload },
    { id: 'import-coa', label: 'Import chart of accounts or opening balances', href: '/accounting/imports', keywords: 'coa opening', any: ['accounting.view_journals'], icon: Upload },
    { id: 'start-close', label: 'Start month-end close', href: '/close', keywords: 'lock period close month', any: ['periods.soft_lock', 'periods.lock'] },
    { id: 'pay-commission', label: 'Approve or pay commission', href: '/commission', keywords: 'agent payout statement', any: ['commission.approve', 'commission.pay'] },
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

export function buildCommands(permissions: string[]): Command[] {
    const held = new Set(permissions);
    const allowed = (any: string[]) => any.length === 0 || any.some((p) => held.has(p));
    return [
        ...navigation.filter((item) => allowed(item.any)).map((item): Command => ({ id: `go-${item.id}`, group: 'Go to', label: `Go to ${item.label.toLowerCase()}`, icon: item.icon, href: item.href })),
        ...actions.filter((action) => allowed(action.any)).map(({ any: _any, icon, ...action }): Command => ({ ...action, group: 'Actions', icon: icon ?? Plus })),
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
