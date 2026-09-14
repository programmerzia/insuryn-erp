<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toasts';

/**
 * Distribution design note §6 targets grid: producers, branches and channels for one period and metric — target (type and press Enter or leave the
 * cell to save), actual production and achievement. Achievement below 100% is not an error, so it is not coloured.
 */
interface Row { id: string; code: string; name: string; target: string | null; actual: string | null; achievement_percent: string | null }
const props = defineProps<{
    periodType: 'monthly' | 'quarterly' | 'annual';
    periodStart: string;
    periodEnd: string;
    metric: 'premium' | 'policies' | 'persistency' | 'collections';
    rows: { producer: Row[]; branch: Row[]; channel: Row[] };
    can: { edit: boolean };
}>();

const subject = ref<'producer' | 'branch' | 'channel'>('producer');
const drafts = ref<Record<string, string>>({});
const saving = ref<string | null>(null);
const metricWords = { premium: 'Written premium (BDT)', policies: 'Policies', persistency: '13th-month persistency (%)', collections: 'Collections (BDT)' };
const visible = computed(() => props.rows[subject.value]);
// GA-40: the grid is the shared table (sort, filter, export); the target cell stays editable in place.
const columns = computed<DataColumn<Row>[]>(() => [
    { id: 'code', header: 'Code', value: (r) => r.code, width: 110 },
    { id: 'name', header: 'Name', value: (r) => r.name, width: 240 },
    { id: 'target', header: 'Target', type: 'number', value: (r) => r.target, width: 160 },
    { id: 'actual', header: 'Actual', type: 'number', value: (r) => (subject.value !== 'producer' && props.metric === 'persistency' ? null : r.actual), width: 160 },
    { id: 'achievement', header: 'Achieved (%)', type: 'number', value: (r) => r.achievement_percent, width: 130 },
]);

function reload(changes: Record<string, string>): void {
    router.get('/distribution/targets', { period_type: props.periodType, period_start: props.periodStart, metric: props.metric, ...changes }, { preserveState: true, replace: true });
}
function save(row: Row): void {
    const value = drafts.value[row.id];
    if (value === undefined || value.trim() === '' || value === (row.target ?? '')) return;
    saving.value = row.id;
    router.put('/distribution/targets', { subject_type: subject.value, subject_id: row.id, period_type: props.periodType, period_start: props.periodStart, metric: props.metric, value }, {
        preserveScroll: true,
        onSuccess: () => delete drafts.value[row.id],
        onError: (errors) => toast(errors.value ?? errors.form ?? 'The target was not saved.', { tone: 'danger', duration: 6000 }),
        onFinish: () => (saving.value = null),
    });
}
</script>

<template>
    <AppLayout help="distribution" title="Targets">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div>
                <Link href="/distribution/producers" class="text-dense text-accent-text hover:underline">Producers</Link>
                <h1 class="text-title font-semibold">Targets</h1>
                <p class="text-ui text-ink-2">{{ formatDate(periodStart) }} to {{ formatDate(periodEnd) }} · {{ metricWords[metric] }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <Field id="targets_period_type" label="Period"><SelectInput id="targets_period_type" :model-value="periodType" :options="[{ value: 'monthly', label: 'Month' }, { value: 'quarterly', label: 'Quarter' }, { value: 'annual', label: 'Year' }]" @update:model-value="(v) => reload({ period_type: String(v) })" /></Field>
                <Field id="targets_start" label="Starting"><DateInput :model-value="periodStart" @update:model-value="(v) => v && reload({ period_start: v })" /></Field>
                <Field id="targets_metric" label="Measure"><SelectInput id="targets_metric" :model-value="metric" :options="Object.entries(metricWords).map(([value, label]) => ({ value, label }))" @update:model-value="(v) => reload({ metric: String(v) })" /></Field>
            </div>
        </div>

        <div class="mb-2 flex h-9 items-end gap-4 border-b border-line" role="tablist" aria-label="Targets for">
            <button v-for="tab in [{ id: 'producer', label: 'Producers' }, { id: 'branch', label: 'Branches' }, { id: 'channel', label: 'Channels' }] as const" :key="tab.id" type="button" role="tab" :aria-selected="subject === tab.id"
                class="-mb-px h-8 border-b-2 text-ui" :class="subject === tab.id ? 'border-accent text-ink' : 'border-transparent text-ink-2 hover:text-ink'" @click="subject = tab.id">{{ tab.label }}</button>
        </div>
        <div class="max-w-[960px] border border-line" data-testid="targets-grid">
            <DataTable :id="`targets-${subject}`" :key="subject" :label="`Targets for ${subject}s`" :columns="columns" :rows="visible" :row-key="(r) => r.id" :url-sync="false" :open-on-click="false" compact-toolbar
                empty-text="Nothing to set targets for yet.">
                <template #cell-code="{ row }"><Link v-if="subject === 'producer'" :href="`/distribution/producers/${row.id}?tab=production`" class="text-accent-text hover:underline">{{ row.code }}</Link><template v-else>{{ row.code }}</template></template>
                <template #cell-target="{ row }">
                    <input v-if="can.edit" :value="drafts[row.id] ?? row.target ?? ''" :aria-label="`Target for ${row.code}`" :inputmode="metric === 'policies' ? 'numeric' : 'decimal'" :disabled="saving === row.id"
                        class="h-7 w-full rounded-control border border-transparent bg-transparent px-2 text-right tabular-nums hover:border-line-control focus:border-line-control focus:bg-surface"
                        placeholder="—" @click.stop @input="(e) => (drafts[row.id] = (e.target as HTMLInputElement).value)" @keydown.enter.prevent="save(row)" @blur="save(row)" />
                    <span v-else class="block px-2 text-right tabular-nums">{{ row.target ?? '—' }}</span>
                </template>
                <template #cell-actual="{ row, value }">{{ value ?? (subject !== 'producer' && metric === 'persistency' ? '—' : row.actual) }}</template>
                <template #cell-achievement="{ row }">{{ row.achievement_percent ?? '—' }}</template>
            </DataTable>
        </div>
    </AppLayout>
</template>
