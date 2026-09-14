import { ref, watch } from 'vue';
import { requestJson } from '@/lib/http';
import { usePreferences } from '@/lib/preferences';

/** Session S5: one plain sentence per journal line, by account role and side, from resources/help/roles.<locale>.md (GET /help/roles). */
export type Captions = Record<string, { debit: string; credit: string }>;

const cache = new Map<string, Promise<Captions>>();

function load(locale: 'en' | 'bn'): Promise<Captions> {
    if (!cache.has(locale)) {
        const request = requestJson<{ captions: Captions }>('GET', `/help/roles?locale=${locale}`).then((r) => r.captions);
        request.catch(() => cache.delete(locale));
        cache.set(locale, request);
    }
    return cache.get(locale)!;
}

/**
 * The caption for a line, or null when its account has no role (an ordinary expense account) or the captions are not loaded yet. GA-24: a caption
 * written for the journal's event (`<EVENT_TYPE>:<role>`, e.g. the unearned premium a cancellation releases) wins over the role's own.
 */
export function captionFor(captions: Captions, role: string | null | undefined, side: 'debit' | 'credit', event?: string | null): string | null {
    if (!role) return null;
    return (event ? captions[`${event}:${role}`]?.[side] : undefined) ?? captions[role]?.[side] ?? null;
}

/** Captions in the user's language, reloaded when the language changes. */
export function useLineCaptions() {
    const preferences = usePreferences();
    const captions = ref<Captions>({});
    watch(() => preferences.locale, async (locale) => {
        captions.value = await load(locale).catch(() => ({}));
    }, { immediate: true });
    return captions;
}
