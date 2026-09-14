import { onBeforeUnmount, ref, type Ref } from 'vue';

/**
 * Gap audit GA-16: below 640 px (Tailwind's `sm`) the shell is a phone layout — the sidebar hides behind the ☰ button in the top bar and opens over the
 * page; desktop keeps the sidebar and its collapse preference unchanged.
 */
export const PHONE_QUERY = '(max-width: 639.98px)';

/** Whether the sidebar is open over the page on a phone. Not a preference: every page load starts with it closed. */
export const navOpen = ref(false);

export function isPhoneWidth(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(PHONE_QUERY).matches;
}

/** A reactive "is this a phone width" that follows window resizes and rotation. */
export function usePhone(): Ref<boolean> {
    const phone = ref(isPhoneWidth());
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return phone;
    const media = window.matchMedia(PHONE_QUERY);
    const update = () => {
        phone.value = media.matches;
        if (!media.matches) navOpen.value = false;
    };
    media.addEventListener?.('change', update);
    onBeforeUnmount(() => media.removeEventListener?.('change', update));
    return phone;
}

/** The top bar's sidebar button: opens or closes the sidebar over the page on a phone, collapses or expands it on a desktop. */
export function toggleNavigation(collapse: () => void): void {
    if (isPhoneWidth()) navOpen.value = !navOpen.value;
    else collapse();
}
