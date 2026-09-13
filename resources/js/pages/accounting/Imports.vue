<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

interface ImportResult {
    type: 'chart-of-accounts' | 'opening-balances';
    mode: 'validate' | 'dry_run' | 'commit';
    valid: boolean;
    errors: { row: number; field: string; message: string }[];
    preview: Record<string, unknown>;
    result: Record<string, unknown> | null;
}

const props = defineProps<{ result: ImportResult | null }>();

const form = useForm<{ type: ImportResult['type']; file: File | null; opening_date: string; mode: ImportResult['mode'] }>({
    type: props.result?.type ?? 'chart-of-accounts',
    file: null,
    opening_date: '',
    mode: 'dry_run',
});

const previewFacts = computed(() =>
    Object.entries(props.result?.preview ?? {}).filter(([, value]) => typeof value !== 'object'),
);

function send(mode: ImportResult['mode']): void {
    form.mode = mode;
    form.post(`/accounting/imports/${form.type}`, { forceFormData: true, preserveScroll: true });
}

function onFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>

<template>
    <AppLayout title="Imports">
        <p class="text-ui font-medium text-ink-2">Validate → dry run → commit</p>
        <h1 class="mt-1 mb-2 text-title font-semibold">Imports</h1>
        <p class="mb-6 max-w-2xl text-ui text-ink-2">
            CSV with a header row. A dry run shows what would be created and writes nothing. Opening balances are committed as an
            opening journal that still needs approval by another user before it posts.
        </p>

        <form class="grid max-w-2xl gap-4 rounded-control border border-line bg-surface p-5" @submit.prevent="send('dry_run')">
            <fieldset class="flex flex-wrap gap-5 text-ui">
                <legend class="mb-2 text-ui font-medium text-ink-2">What to import</legend>
                <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="chart-of-accounts" /> Chart of accounts</label>
                <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="opening-balances" /> Opening balances</label>
            </fieldset>
            <label class="grid gap-1 text-dense text-ink-2" for="import-file">
                CSV file
                <input id="import-file" type="file" accept=".csv,text/csv" class="text-ui text-ink" @change="onFile" />
            </label>
            <label v-if="form.type === 'opening-balances'" class="grid gap-1 text-dense text-ink-2" for="opening-date">
                Opening date
                <input id="opening-date" v-model="form.opening_date" type="date" class="w-48 rounded-control border border-line-control bg-surface px-2 py-1.5 text-ui text-ink" />
            </label>
            <p v-for="(message, field) in form.errors" :key="field" class="text-ui text-danger">{{ message }}</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-control border border-line-control px-3 py-1.5 text-ui hover:bg-surface-2" :disabled="form.processing" @click="send('validate')">Validate</button>
                <button type="submit" class="rounded-control border border-line-control px-3 py-1.5 text-ui hover:bg-surface-2" :disabled="form.processing">Dry run</button>
                <button type="button" class="rounded-control bg-accent px-3 py-1.5 text-ui font-medium text-ink hover:bg-accent-hover" :disabled="form.processing" @click="send('commit')">Commit</button>
            </div>
        </form>

        <section v-if="result" class="mt-8 max-w-4xl" aria-labelledby="outcome">
            <h2 id="outcome" class="mb-3 text-section font-semibold">
                {{ result.valid ? (result.result ? 'Committed' : 'No problems found') : `${result.errors.length} problem(s) — nothing was written` }}
            </h2>

            <Table v-if="result.errors.length > 0">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="w-20">Row</TableHead>
                        <TableHead class="w-40">Field</TableHead>
                        <TableHead>Problem</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(error, index) in result.errors" :key="index">
                        <TableCell class="">{{ error.row === 0 ? 'file' : error.row }}</TableCell>
                        <TableCell class=" text-ink-2">{{ error.field }}</TableCell>
                        <TableCell class="text-danger">{{ error.message }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <dl v-else class="grid grid-cols-2 gap-px overflow-hidden rounded-control border border-line bg-line sm:grid-cols-4">
                <div v-for="[term, value] in previewFacts" :key="term" class="bg-surface px-4 py-3">
                    <dt class="text-ui font-medium text-ink-2">{{ term.replaceAll('_', ' ') }}</dt>
                    <dd class="mt-1 text-ui">{{ value }}</dd>
                </div>
                <div v-for="(value, term) in result.result ?? {}" :key="`result-${term}`" class="bg-surface-2 px-4 py-3">
                    <dt class="text-dense font-semibold text-ok">{{ String(term).replaceAll('_', ' ') }}</dt>
                    <dd class="mt-1 text-ui break-all">{{ value }}</dd>
                </div>
            </dl>
        </section>
    </AppLayout>
</template>
