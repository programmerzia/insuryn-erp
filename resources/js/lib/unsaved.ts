import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { confirmAction } from '@/lib/confirm';

/**
 * Unsaved-changes guard (brief §4 "Esc cancel with unsaved-changes guard"): leaving the page by a link, Back, Esc or closing the tab asks
 * first while `dirty()` is true. Submitting the form itself (a non-GET visit) is never blocked.
 */
export function useUnsavedGuard(dirty: () => boolean): { leave: (href: string) => Promise<void> } {
    let allowed = false;
    let removeBefore: (() => void) | undefined;
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
        if (dirty() && !allowed) {
            event.preventDefault();
        }
    };

    async function ask(): Promise<boolean> {
        return confirmAction({ title: 'Leave without saving?', body: 'Your changes on this form will be lost.', confirmLabel: 'Leave and discard', cancelLabel: 'Stay on the form', tone: 'danger' });
    }

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        removeBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (allowed || !dirty() || visit.method !== 'get') return;
            event.preventDefault();
            void ask().then((ok) => {
                if (!ok) return;
                allowed = true;
                router.visit(visit.url, { method: visit.method, data: visit.data, preserveState: visit.preserveState, replace: visit.replace });
            });
        });
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeBefore?.();
    });

    return {
        async leave(href: string) {
            if (!dirty() || (await ask())) {
                allowed = true;
                router.visit(href);
            }
        },
    };
}
