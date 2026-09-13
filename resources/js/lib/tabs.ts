import type { Tab } from '@/lib/preferences';

/** Pinned object tabs (UX brief §3): Ctrl+click pins, at most eight, persisted with the user's preferences. */
export const MAX_TABS = 8;

export function pinTab(tabs: Tab[], tab: Tab): { ok: boolean; tabs: Tab[] } {
    const existing = tabs.findIndex((t) => t.href === tab.href);
    if (existing !== -1) {
        return { ok: true, tabs: tabs.map((t, i) => (i === existing ? tab : t)) };
    }
    if (tabs.length >= MAX_TABS) {
        return { ok: false, tabs };
    }
    return { ok: true, tabs: [...tabs, tab] };
}

export function unpinTab(tabs: Tab[], href: string): Tab[] {
    return tabs.filter((t) => t.href !== href);
}
