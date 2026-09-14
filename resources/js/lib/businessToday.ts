import { usePage } from '@inertiajs/vue3';
import { todayIso } from '@/lib/calendar';

/**
 * Gap fix GA-19: the company's today (slice 2.1b `businessToday`, shared by the server on every page) for drawers that record something happening now
 * — paying, depositing, bouncing, approving. Pages that already receive a `today` prop keep using it; outside an Inertia page (a bare test mount) the
 * browser's date.
 */
export function sharedBusinessToday(props: Record<string, unknown> | null | undefined): string | null {
    const value = props?.businessToday;
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null;
}

export function useBusinessToday(): string {
    try {
        return sharedBusinessToday(usePage()?.props as Record<string, unknown> | undefined) ?? todayIso();
    } catch {
        return todayIso();
    }
}
