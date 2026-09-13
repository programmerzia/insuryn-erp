/**
 * Breadcrumb drill (UX brief §6.6): Report → ledger → journal → event → source document. Each drill link records where it came from, so the
 * page it opens can show the way back. Kept for the browser tab only (sessionStorage), falling back to no trail.
 */
export interface Crumb {
    label: string;
    href: string;
}

const KEY = 'insuryn.drill';

function read(): Crumb[] {
    try {
        return JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as Crumb[];
    } catch {
        return [];
    }
}

function path(href: string): string {
    return href.split('#')[0] ?? href;
}

/** Call when following a drill link from the current page. */
export function drillFrom(label: string, fromHref: string = window.location.pathname + window.location.search): void {
    const trail = read();
    const index = trail.findIndex((c) => path(c.href) === path(fromHref));
    const next = index === -1 ? [...trail, { label, href: fromHref }] : trail.slice(0, index + 1);
    try {
        sessionStorage.setItem(KEY, JSON.stringify(next.slice(-6)));
    } catch {
        // storage unavailable: no breadcrumb, navigation still works
    }
}

/** The trail leading to the current page (without the current page itself). A page opened directly starts a new trail. */
export function currentTrail(here: string = window.location.pathname + window.location.search): Crumb[] {
    const trail = read();
    const index = trail.findIndex((c) => path(c.href) === path(here));
    return index === -1 ? trail : trail.slice(0, index);
}

export function startTrail(): void {
    try {
        sessionStorage.removeItem(KEY);
    } catch {
        // ignore
    }
}
