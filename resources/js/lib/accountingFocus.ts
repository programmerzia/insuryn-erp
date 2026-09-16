import { router, usePage } from '@inertiajs/vue3';
import { activeItem, ACCOUNTING_FOCUS_LANDING, filterNavigationFocus, visibleNavigation } from '@/lib/navigation';
import { savePreference, usePreferences } from '@/lib/preferences';
import type { SharedProps } from '@/types/shared';

/** Turn accounting focus on or off; when turning on, leave pages hidden from the filtered menu. */
export function toggleAccountingFocus(): void {
    const preferences = usePreferences();
    const page = usePage<SharedProps>();
    const next = !preferences.accounting_focus;
    savePreference('accounting_focus', next, 0);
    if (next && !isPageVisibleInAccountingFocus(page.url, page.props.auth.permissions ?? [])) {
        router.visit(ACCOUNTING_FOCUS_LANDING);
    }
}

export function isPageVisibleInAccountingFocus(url: string, permissions: string[]): boolean {
    const items = filterNavigationFocus(visibleNavigation(permissions), true);
    return activeItem(items, url) !== undefined;
}
