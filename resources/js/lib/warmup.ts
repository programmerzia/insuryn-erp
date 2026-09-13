/** Page code loaders, the same chunks app.ts resolves pages from. */
const pages = import.meta.glob('../pages/**/*.vue');
let warmed = false;

/**
 * Fetch the code of the pages a user can reach from the sidebar once the browser is idle, so a first visit to them needs only its data
 * (UX brief §7: navigations fast on slow connections). Runs once per page load.
 */
export function warmPages(components: string[]): void {
    if (warmed || typeof window === 'undefined') return;
    warmed = true;
    const idle = (window as Window & { requestIdleCallback?: (cb: () => void) => void }).requestIdleCallback ?? ((cb: () => void) => setTimeout(cb, 1500));
    idle(() => {
        components.forEach((name) => void pages[`../pages/${name}.vue`]?.());
        void import('@/components/shell/CommandPalette.vue');
    });
}
