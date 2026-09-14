import { beforeEach, describe, expect, it, vi } from 'vitest';
import { applyPreference, defaultPreferences, type Preferences } from '@/lib/preferences';
import { formatKeys, matchesKeys, shortcutKeys } from '@/lib/shortcuts';
import { pinTab, unpinTab } from '@/lib/tabs';

const key = (init: KeyboardEventInit) => new KeyboardEvent('keydown', init);

describe('shortcut registry', () => {
    it('matches a chord regardless of letter case and requires exactly its modifiers', () => {
        expect(matchesKeys(key({ key: 'k', ctrlKey: true }), 'Ctrl+K')).toBe(true);
        expect(matchesKeys(key({ key: 'K', ctrlKey: true, shiftKey: true }), 'Ctrl+K')).toBe(false);
        expect(matchesKeys(key({ key: 'k', metaKey: true }), 'Ctrl+K')).toBe(true); // ⌘ counts as Ctrl on a Mac
        expect(matchesKeys(key({ key: 'Enter', ctrlKey: true }), 'Ctrl+Enter')).toBe(true);
        expect(matchesKeys(key({ key: 'Escape' }), 'Esc')).toBe(true);
        expect(matchesKeys(key({ key: '/' }), '/')).toBe(true);
        expect(matchesKeys(key({ key: 'b' }), 'Ctrl+B')).toBe(false);
    });

    it('is the one place menus read their shortcut from', () => {
        expect(shortcutKeys('app.palette')).toBe('Ctrl+K');
        expect(shortcutKeys('app.sidebar')).toBe('Ctrl+B');
        expect(formatKeys('Ctrl+Enter')).toEqual(['Ctrl', 'Enter']);
    });
});

describe('preferences', () => {
    let prefs: Preferences;
    beforeEach(() => {
        prefs = defaultPreferences();
    });

    it('applies top-level and grouped keys locally', () => {
        applyPreference(prefs, 'theme', 'dark');
        applyPreference(prefs, 'splits.receipts', 420);
        applyPreference(prefs, 'splits.claims', 380);
        expect(prefs.theme).toBe('dark');
        expect(prefs.splits).toEqual({ receipts: 420, claims: 380 });
    });

    it('remembers which sidebar sections the user opened or closed', () => {
        expect(prefs.sidebar_sections).toEqual({});
        applyPreference(prefs, 'sidebar_sections.accounting', true);
        applyPreference(prefs, 'sidebar_sections.sales', false);
        expect(prefs.sidebar_sections).toEqual({ accounting: true, sales: false });
    });
});

describe('pinned tabs', () => {
    it('pins once per address, keeps at most eight and refuses a ninth', () => {
        let tabs: { href: string; title: string }[] = [];
        for (let i = 1; i <= 8; i++) {
            const result = pinTab(tabs, { href: `/policies/${i}`, title: `POL-${i}` });
            expect(result.ok).toBe(true);
            tabs = result.tabs;
        }
        expect(pinTab(tabs, { href: '/policies/3', title: 'POL-3 renamed' }).tabs).toHaveLength(8);
        const ninth = pinTab(tabs, { href: '/policies/9', title: 'POL-9' });
        expect(ninth.ok).toBe(false);
        expect(ninth.tabs).toHaveLength(8);
        expect(unpinTab(tabs, '/policies/1')).toHaveLength(7);
    });
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }), router: { on: () => () => undefined } }));
