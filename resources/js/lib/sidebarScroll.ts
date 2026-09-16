/** Persists sidebar scroll across Inertia page swaps (AppLayout remounts on each visit). */
const KEY = 'sidebar_scroll';

export function readSidebarScroll(): number {
    try {
        const raw = sessionStorage.getItem(KEY);
        const top = raw ? parseInt(raw, 10) : 0;
        return Number.isFinite(top) && top >= 0 ? top : 0;
    } catch {
        return 0;
    }
}

export function writeSidebarScroll(top: number): void {
    try {
        sessionStorage.setItem(KEY, String(Math.max(0, Math.round(top))));
    } catch {
        // storage unavailable
    }
}
