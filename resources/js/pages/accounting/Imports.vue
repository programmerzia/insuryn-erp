<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import Stepper from '@/components/forms/Stepper.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';

/**
 * Spec §7 import flow on the brief's stepper: choose the file → check it → dry run (what would be created, nothing written) → commit.
 * Opening balances commit as an opening journal that another person approves before it posts.
 */
interface ImportResult {
    type: 'chart-of-accounts' | 'opening-balances';
    mode: 'validate' | 'dry_run' | 'commit';
    valid: boolean;
    errors: { row: number; field: string; message: string }[];
    preview: Record<string, unknown>;
    result: Record<string, unknown> | null;
}
const props = defineProps<{ result: ImportResult | null }>();

const form = useForm<{ type: ImportResult['type']; file: File | null; opening_date: string; mode: ImportResult['mode'] }>({ type: props.result?.type ?? 'chart-of-accounts', file: null, opening_date: '', mode: 'dry_run' });
const steps = [{ id: 'file', label: 'File' }, { id: 'check', label: 'Check' }, { id: 'dry', label: 'Dry run' }, { id: 'commit', label: 'Commit' }];
const current = computed(() => (!props.result ? 0 : props.result.result ? 3 : !props.result.valid ? 1 : props.result.mode === 'validate' ? 1 : 2));
const facts = computed(() => Object.entries(props.result?.preview ?? {}).filter(([, v]) => typeof v !== 'object'));
const words = (v: string) => v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());

async function send(mode: ImportResult['mode']): Promise<void> {
    if (mode === 'commit') {
        const ok = await confirmAction({ title: 'Commit the import?', body: form.type === 'opening-balances' ? 'The opening journal is created and waits for another person to approve and post it.' : 'The accounts are created in the chart of accounts.', confirmLabel: 'Commit' });
        if (!ok) return;
    }
    form.mode = mode;
    form.post(`/accounting/imports/${form.type}`, { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout help="accounting" title="Imports">
        <h1 class="mb-4 text-title font-semibold">Import chart of accounts or opening balances</h1>
        <Stepper :steps="steps" :current="current">
            <form class="grid max-w-[560px] gap-4" @submit.prevent="send('dry_run')">
                <fieldset class="grid gap-1.5">
                    <legend class="mb-1 text-ui font-medium">What to import</legend>
                    <label class="flex items-center gap-2 text-ui"><input v-model="form.type" type="radio" value="chart-of-accounts" class="accent-accent" />Chart of accounts</label>
                    <label class="flex items-center gap-2 text-ui"><input v-model="form.type" type="radio" value="opening-balances" class="accent-accent" />Opening balances</label>
                </fieldset>
                <Field id="import-file" label="CSV file" hint="A header row, amounts in taka with a dot for decimals." :error="form.errors.file">
                    <input id="import-file" type="file" accept=".csv,text/csv" class="text-ui text-ink file:mr-3 file:h-8 file:rounded-control file:border file:border-line-control file:bg-surface file:px-3 file:text-ui file:text-ink" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                </Field>
                <Field v-if="form.type === 'opening-balances'" id="opening_date" label="Opening date" :error="form.errors.opening_date"><DateInput v-model="form.opening_date" /></Field>
                <p v-if="(form.errors as Record<string, string>).form" class="text-ui text-danger" role="alert">{{ (form.errors as Record<string, string>).form }}</p>
                <div class="flex flex-wrap gap-2 border-t border-line pt-4">
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="!form.file || form.processing" @click="send('validate')">Check the file</button>
                    <button type="submit" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="!form.file || form.processing">Dry run</button>
                    <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" :disabled="!form.file || form.processing || !result?.valid" @click="send('commit')">Commit</button>
                </div>
            </form>
            <section v-if="result" class="mt-6 max-w-[880px]" aria-labelledby="outcome">
                <h2 id="outcome" class="mb-2 text-section font-semibold">
                    {{ result.valid ? (result.result ? 'Committed' : 'No problems found; nothing was written') : `${result.errors.length} ${result.errors.length === 1 ? 'problem' : 'problems'}; nothing was written` }}
                </h2>
                <table v-if="result.errors.length" class="w-full table-fixed border-separate border-spacing-0 border border-line text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-8"><th class="w-20 border-b border-line px-3 text-left font-medium">Row</th><th class="w-40 border-b border-line px-3 text-left font-medium">Field</th><th class="border-b border-line px-3 text-left font-medium">What is wrong</th></tr></thead>
                    <tbody><tr v-for="(error, index) in result.errors" :key="index" class="h-8"><td class="border-b border-line px-3 tabular-nums">{{ error.row === 0 ? 'File' : error.row }}</td><td class="border-b border-line px-3 text-ink-2">{{ error.field }}</td><td class="border-b border-line px-3 text-danger">{{ error.message }}</td></tr></tbody>
                </table>
                <dl v-else class="grid grid-cols-[minmax(10rem,auto)_1fr] gap-x-4 gap-y-2 text-ui">
                    <template v-for="[term, value] in facts" :key="term"><dt class="text-ink-2">{{ words(term) }}</dt><dd class="tabular-nums">{{ value }}</dd></template>
                    <template v-for="(value, term) in result.result ?? {}" :key="`r-${term}`"><dt class="text-ink-2">{{ words(String(term)) }}</dt><dd class="break-all">{{ value }}</dd></template>
                </dl>
            </section>
            <template #summary>
                <dl class="grid gap-2 text-ui">
                    <div><dt class="text-dense text-ink-2">Importing</dt><dd>{{ form.type === 'chart-of-accounts' ? 'Chart of accounts' : 'Opening balances' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">File</dt><dd class="break-all">{{ form.file?.name ?? 'Not chosen yet' }}</dd></div>
                    <div v-if="result"><dt class="text-dense text-ink-2">Last check</dt><dd :class="result.valid ? 'text-ok' : 'text-danger'">{{ result.valid ? 'Valid' : `${result.errors.length} problems` }}</dd></div>
                </dl>
            </template>
        </Stepper>
    </AppLayout>
</template>
