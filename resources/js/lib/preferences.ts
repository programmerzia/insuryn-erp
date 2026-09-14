import { usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { requestJson } from '@/lib/http';

/** Per-user interface state, saved server-side (UX brief §4; Platform\Preferences\UserPreferences validates every key). */
export interface Tab {
    href: string;
    title: string;
}
export interface TableLayout {
    columns?: string[];
    hidden?: string[];
    widths?: Record<string, number>;
    sort?: { id: string; desc: boolean }[];
}
export interface SavedView {
    name: string;
    query: string;
    columns?: string[];
}
export interface Recent {
    kind: string;
    label: string;
    href: string;
}
export interface Preferences {
    theme: 'system' | 'light' | 'dark';
    density: 'compact' | 'comfortable';
    sidebar_collapsed: boolean;
    /** Sidebar sections the user has opened or closed, by section id (unset = the section's default). */
    sidebar_sections: Record<string, boolean>;
    branch_id: string | null;
    splits: Record<string, number>;
    tabs: Tab[];
    tables: Record<string, TableLayout>;
    views: Record<string, SavedView[]>;
    recents: Recent[];
    drafts: Record<string, unknown>;
    /** Session S3: the "How this works" panel's language and whether it is open. */
    locale: 'en' | 'bn';
    help_open: boolean;
    /** GA-30: the help panel's language when it differs from the user's; null follows `locale`. */
    help_locale: 'en' | 'bn' | null;
    /** Session S4: guided tour progress; null until the user starts or dismisses it. */
    tour: TourState | null;
}
export interface TourState {
    status: 'active' | 'dismissed' | 'finished';
    step: number;
}

export function defaultPreferences(): Preferences {
    return { theme: 'system', density: 'compact', sidebar_collapsed: false, sidebar_sections: {}, branch_id: null, splits: {}, tabs: [], tables: {}, views: {}, recents: [], drafts: {}, locale: 'en', help_open: false, help_locale: null, tour: null };
}

/** Sets `theme` or a grouped key such as `splits.receipts` on a preferences object. */
export function applyPreference(target: Preferences, key: string, value: unknown): void {
    const [name, id] = key.split('.', 2) as [keyof Preferences, string | undefined];
    if (id === undefined) {
        (target as unknown as Record<string, unknown>)[name] = value;
        return;
    }
    const group = { ...((target[name] as Record<string, unknown>) ?? {}) };
    group[id] = value;
    (target as unknown as Record<string, unknown>)[name] = group;
}

const state = reactive<Preferences>(defaultPreferences());
const pending = new Map<string, ReturnType<typeof setTimeout>>();
let hydrated = false;

/** The signed-in user's preferences: hydrated once from the shared page props, then updated optimistically in place. */
export function usePreferences(): Preferences {
    if (!hydrated) {
        const shared = usePage().props.preferences as Partial<Preferences> | undefined;
        Object.assign(state, defaultPreferences(), shared ?? {});
        hydrated = true;
        watch(() => [state.theme, state.density] as const, applyToDocument, { immediate: true });
    }
    return state;
}

/** Changes a preference now and saves it shortly after (coalescing rapid changes such as dragging a divider). */
export function savePreference(key: string, value: unknown, delay = 400): void {
    applyPreference(state, key, value);
    clearTimeout(pending.get(key));
    pending.set(key, setTimeout(() => {
        pending.delete(key);
        void requestJson('PUT', `/preferences/${key}`, { value }).catch(() => undefined);
    }, delay));
}

function applyToDocument([theme, density]: readonly [Preferences['theme'], Preferences['density']]): void {
    if (typeof document === 'undefined') {
        return;
    }
    const root = document.documentElement;
    if (theme === 'system') {
        delete root.dataset.theme;
    } else {
        root.dataset.theme = theme;
    }
    root.dataset.density = density;
}
