import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * True while a same-page reload (filter, sort, pagination, period change) takes longer than a moment, so tables show skeleton rows in place
 * instead of a spinner over the screen (brief §4). Form submissions and page changes are not counted.
 */
export const reloading = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;
let listening = false;

export function useReloading() {
    if (!listening && typeof window !== 'undefined') {
        listening = true;
        router.on('start', (event) => {
            const visit = event.detail.visit;
            if (visit.method === 'get' && visit.preserveState && visit.url.pathname === window.location.pathname) {
                timer = setTimeout(() => (reloading.value = true), 250);
            }
        });
        router.on('finish', () => {
            clearTimeout(timer);
            reloading.value = false;
        });
    }
    return reloading;
}
