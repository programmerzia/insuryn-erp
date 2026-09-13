import { type InertiaForm, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { type PreviewResult, previewJournal } from '@/lib/preview';

/**
 * A form whose submit moves money (brief §1.6): Review asks the server for the journal it would post and shows it; Confirm posts. Field and
 * business-rule refusals land on the form. `onDone` runs after a successful post (close the drawer).
 */
export function useMoneyForm<T extends Record<string, unknown>>(url: () => string, initial: T, onDone: () => void = () => undefined) {
    const form = useForm(initial as never) as unknown as InertiaForm<T>;
    const preview = ref<PreviewResult | null>(null);
    const previewOpen = ref(false);

    async function review(): Promise<void> {
        form.clearErrors();
        const outcome = await previewJournal(url(), form.data() as Record<string, unknown>);
        if (!outcome.ok) {
            form.setError(outcome.errors as never);
            return;
        }
        preview.value = outcome.result;
        previewOpen.value = true;
    }

    function post(): void {
        form.post(url(), { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); }, onFinish: () => (previewOpen.value = false) });
    }

    return { form, preview, previewOpen, review, post };
}
