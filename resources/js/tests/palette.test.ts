import { describe, expect, it, vi } from 'vitest';
import { buildCommands, rankCommands, rememberRecent } from '@/lib/commands';
import { fuzzyScore } from '@/lib/fuzzy';
import { navigation } from '@/lib/navigation';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }), router: { on: () => () => undefined, visit: () => undefined } }));

describe('fuzzy matching', () => {
    it('matches subsequences and prefers word starts and prefixes', () => {
        expect(fuzzyScore('claims', 'Go to claims')).toBeGreaterThan(0);
        expect(fuzzyScore('gtc', 'Go to claims')).toBeGreaterThan(0);
        expect(fuzzyScore('xyz', 'Go to claims')).toBe(0);
        expect(fuzzyScore('rec', 'Record receipt')).toBeGreaterThan(fuzzyScore('rec', 'Go to unallocated receipts'));
        expect(fuzzyScore('POL-1042', 'POL-2026-001042')).toBeGreaterThan(0); // short document numbers find the full one
        expect(fuzzyScore('', 'anything')).toBe(1);
    });
});

describe('commands', () => {
    const commands = buildCommands(['claim.register', 'reports.financial']);

    it('lists navigation and actions the user may open, with shortcuts from the registry', () => {
        const labels = commands.map((c) => c.label);
        expect(labels).toContain('Go to claims');
        expect(labels).toContain('Register a claim');
        expect(labels).not.toContain('Record a receipt');
        expect(labels).not.toContain('Go to journals');
        expect(commands.find((c) => c.label === 'Collapse or expand the sidebar')?.shortcut).toBe('app.sidebar');
    });

    it('opens the rated quote workbench for New quote, never the typed-premium form (GA-11)', () => {
        const officer = buildCommands(['quotation.create', 'policy.create']);
        expect(officer.find((c) => c.id === 'new-quote')?.href).toBe('/quotations/create');
        expect(officer.some((c) => c.href === '/policies/create')).toBe(false);
        expect(buildCommands(['policy.create']).some((c) => c.id === 'new-quote')).toBe(false);
    });

    it('approves and pays commission only in the commission statements run, with one producer register (GA-10)', () => {
        const finance = buildCommands(['commission.approve', 'commission.pay', 'agent.manage', 'party.manage', 'policy.create', 'reports.financial']);
        expect(finance.find((c) => c.id === 'pay-commission')?.href).toBe('/distribution/statements');
        expect(finance.filter((c) => c.href === '/distribution/statements').map((c) => c.label)).toContain('Go to commission statements');
        expect(finance.some((c) => c.href === '/commission' || c.href === '/agents')).toBe(false);
        expect(finance.filter((c) => c.href === '/distribution/producers')).toHaveLength(1);
    });

    it('names sidebar items in the glossary\'s words, each with an icon of its own (GA-37)', () => {
        const labels = navigation.map((item) => item.label);
        expect(labels).toEqual(expect.arrayContaining(['Bank accounts', 'Month-end close', 'Compensation schemes', 'Cheque register', 'Payment reminders', 'Commission statements', 'Document templates', 'Accounting events', 'Ledger API demo']));
        expect(labels).not.toEqual(expect.arrayContaining(['Agents']));
        expect(new Set(navigation.map((item) => item.icon)).size).toBe(navigation.length);
        expect(new Set(labels).size).toBe(labels.length);
    });

    it('ranks recent commands first when nothing is typed, then by match', () => {
        const recents = [{ kind: 'navigate', label: 'Go to reports', href: '/reports' }];
        expect(rankCommands(commands, '', recents)[0]?.label).toBe('Go to reports');
        expect(rankCommands(commands, 'register', recents)[0]?.label).toBe('Register a claim');
    });

    it('remembers up to twenty recent items, newest first, once each', () => {
        let recents: { kind: string; label: string; href: string }[] = [];
        for (let i = 0; i < 25; i++) {
            recents = rememberRecent(recents, { kind: 'policy', label: `POL-${i}`, href: `/policies/${i}` });
        }
        recents = rememberRecent(recents, { kind: 'policy', label: 'POL-10', href: '/policies/10' });
        expect(recents).toHaveLength(20);
        expect(recents[0]?.href).toBe('/policies/10');
        expect(recents.filter((r) => r.href === '/policies/10')).toHaveLength(1);
    });
});
