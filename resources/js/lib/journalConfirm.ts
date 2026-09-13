import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { type PreviewResult, previewJournal } from '@/lib/preview';
import { toast } from '@/lib/toasts';

/**
 * Deliberate friction where money moves (brief §1.6): ask the server for the journal an action would post, show it, and only then post.
 * A refusal (validation, business rule, permission) is shown as a toast that says what happened.
 */
export function useJournalConfirm() {
    const state = reactive({ open: false, processing: false, result: null as PreviewResult | null, title: '', label: '', url: '', data: {} as Record<string, unknown> });

    async function request(url: string, data: Record<string, unknown>, title: string, label: string): Promise<void> {
        const outcome = await previewJournal(url, data);
        if (!outcome.ok) {
            toast(outcome.errors.form ?? Object.values(outcome.errors)[0] ?? 'The action was refused.', { tone: 'danger', duration: 6000 });
            return;
        }
        Object.assign(state, { open: true, result: outcome.result, title, label, url, data });
    }

    function confirm(): void {
        router.post(state.url, state.data as never, {
            preserveScroll: true,
            onStart: () => (state.processing = true),
            onFinish: () => Object.assign(state, { processing: false, open: false }),
        });
    }

    return { state, request, confirm };
}
