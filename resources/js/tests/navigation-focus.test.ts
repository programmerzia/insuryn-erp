import { describe, expect, it } from 'vitest';
import { buildCommands } from '@/lib/commands';
import { filterNavigationFocus, navigation, visibleNavigation } from '@/lib/navigation';

const financePerms = [
    'accounting.view_journals', 'accounting.create_manual_journal', 'reports.financial', 'ap.enter_bills', 'fa.manage', 'budget.prepare', 'bank.import', 'claim.register',
];

describe('accounting focus mode', () => {
    it('keeps accounting, assets, payables and bank only', () => {
        const all = visibleNavigation(financePerms);
        const focused = filterNavigationFocus(all, true);
        const sections = new Set(focused.map((item) => item.section));
        expect(sections.has('accounting')).toBe(true);
        expect(sections.has('assets')).toBe(true);
        expect(sections.has('payables')).toBe(true);
        expect(focused.filter((item) => item.section === 'collections').map((item) => item.id)).toEqual(['bank']);
        expect(focused.some((item) => item.id === 'bank')).toBe(true);
        expect(focused.some((item) => item.id === 'policies')).toBe(false);
        expect(focused.some((item) => item.id === 'ledger-api')).toBe(true);
        expect(focused.some((item) => item.id === 'budgets')).toBe(true);
    });

    it('returns every permitted item when focus is off', () => {
        const all = visibleNavigation(financePerms);
        expect(filterNavigationFocus(all, false)).toEqual(all);
    });

    it('limits command palette actions to accounting workflows', () => {
        const full = buildCommands(financePerms, false);
        const focused = buildCommands(financePerms, true);
        expect(focused.some((c) => c.label === 'Register a claim')).toBe(false);
        expect(focused.some((c) => c.label === 'New manual journal')).toBe(true);
        expect(focused.length).toBeLessThan(full.length);
    });

    it('does not change the master navigation catalogue', () => {
        expect(navigation.some((item) => item.id === 'policies')).toBe(true);
    });
});
