<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { drillFrom } from '@/lib/drill';
import { eventLabel } from '@/lib/events';
import { formatMoney } from '@/lib/format';

/** One report on the shared table: period filter, drill links on rows (brief §6.6), totals under the table, the drill path above it. */
interface Row { cells: Record<string, string | number | null>; link: string | null; __key: string }
const props = defineProps<{
    report: string;
    title: string;
    filter: 'range' | 'as_of' | 'range_by';
    filters: { from: string; to: string; as_of: string; by: string };
    columns: { key: string; label: string; align: 'left' | 'right' }[];
    rows: { cells: Record<string, string | number | null>; link: string | null }[];
    totals: Record<string, string>;
}>();

// A report opened without a start date covers everything before `to`; show that instead of a default start that was not applied.
const filters = reactive({ ...props.filters, from: typeof window !== 'undefined' && !new URLSearchParams(window.location.search).has('from') && props.report === 'account-activity' ? '' : props.filters.from });
const active = ref<string | null>(null);
const rows = computed<Row[]>(() => props.rows.map((row, index) => ({ ...row, __key: String(index) })));
const isMoney = (value: unknown) => typeof value === 'string' && /^\(?-?[\d,]+\.\d{2}\)?$/.test(value);
const columns = computed<DataColumn<Row>[]>(() =>
    props.columns.map((column, index) => {
        const sample = props.rows.find((r) => r.cells[column.key] !== null)?.cells[column.key];
        const type = column.align === 'right' ? (isMoney(sample) ? 'money' : 'number') : /date|as_of|on$/.test(column.key) ? 'date' : 'text';
        return { id: column.key, header: column.label, type, value: (row: Row) => { const v = row.cells[column.key]; return typeof v === 'string' && /^[A-Z][A-Z_]+$/.test(v) ? eventLabel(v) : v; }, href: index === 0 ? (row: Row) => row.link : undefined, width: type === 'text' ? 220 : 140 };
    }),
);

function apply(): void {
    const query: Record<string, string> = Object.fromEntries(new URLSearchParams(window.location.search).entries());
    if (props.filter === 'as_of') query.as_of = filters.as_of;
    else Object.assign(query, { from: filters.from, to: filters.to }, props.filter === 'range_by' ? { by: filters.by } : {});
    router.get(`/reports/${props.report}`, query, { preserveState: true });
}

function open(row: Row): void {
    if (!row.link) return;
    drillFrom(props.title);
    router.visit(row.link);
}
</script>

<template>
    <AppLayout help="reports" :title="title" fill>
        <div class="border-b border-line px-4 pt-2"><Breadcrumb :base="[{ label: 'Reports', href: '/reports' }]" /></div>
        <div class="flex min-h-0 flex-1 flex-col" @click.capture="(e) => (e.target as HTMLElement).closest('tbody a[href]') && drillFrom(title)">
        <DataTable id="report" v-model:active="active" :label="title" :columns="columns" :rows="rows" :row-key="(r) => r.__key" currency="BDT" :url-sync="false" empty-text="Nothing in this period." @open="open">
            <template #toolbar>
                <h1 class="mr-2 text-section font-semibold">{{ title }}</h1>
                <form class="flex items-center gap-1.5 text-ui text-ink-2" @submit.prevent="apply">
                    <template v-if="filter === 'as_of'"><label for="report-as-of">As of</label><DateInput id="report-as-of" v-model="filters.as_of" class="w-32" @update:model-value="apply" /></template>
                    <template v-else>
                        <label for="report-from">From</label><DateInput id="report-from" v-model="filters.from" class="w-32" @update:model-value="apply" />
                        <label for="report-to">to</label><DateInput id="report-to" v-model="filters.to" class="w-32" @update:model-value="apply" />
                        <select v-if="filter === 'range_by'" v-model="filters.by" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body text-ink" aria-label="Group by" @change="apply">
                            <option v-for="by in ['product', 'branch', 'agent']" :key="by" :value="by">By {{ by }}</option>
                        </select>
                    </template>
                </form>
            </template>
        </DataTable>
        </div>
        <dl v-if="Object.keys(totals).length" class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-line bg-surface-2 px-4 py-2 text-ui">
            <div v-for="(amount, label) in totals" :key="label" class="flex items-baseline gap-2">
                <dt class="text-ink-2">{{ String(label).replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()) }}</dt>
                <dd class="tabular-nums font-medium">{{ formatMoney(amount) }}</dd>
            </div>
        </dl>
    </AppLayout>
</template>
