<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { drillFrom } from '@/lib/drill';
import { eventLabel, isEventType } from '@/lib/events';
import { formatMoney } from '@/lib/format';
import { reportColumnWidth } from '@/lib/reportColumns';
const currency = useEntityCurrency();

/**
 * One report on the shared table: period filter, drill links on rows and on cells such as a policy number (brief §6.6), totals under the table,
 * summary tables (subtotals, a reconciliation to the ledger) below them, the drill path above it.
 */
interface Row { cells: Record<string, string | number | null>; link: string | null; links?: Record<string, string>; __key: string }
interface Summary { title: string; columns: { key: string; label: string }[]; rows: { cells: Record<string, string | number | null>; link: string | null }[] }
const props = defineProps<{
    report: string;
    title: string;
    filter: 'range' | 'as_of' | 'range_by';
    filters: { from: string; to: string; as_of: string; by: string };
    columns: { key: string; label: string; align: 'left' | 'right' }[];
    rows: { cells: Record<string, string | number | null>; link: string | null; links?: Record<string, string> }[];
    totals: Record<string, string>;
    summaries?: Summary[];
    /** Flow fix X11: the other financial statements for the same period. */
    related?: { label: string; href: string }[];
    /** Design addendum v2 §B.7: a register served by its own screen filters at its own URL. */
    baseUrl?: string;
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
        // Gap audit GA-34: only event types become words ("HO" stays HO); columns are sized to their header and values (GA-06: no cut-off headers).
        const header = type === 'money' ? `${column.label} (${currency.value})` : column.label;
        const width = reportColumnWidth(header, props.rows.map((r) => r.cells[column.key]), type);
        return { id: column.key, header: column.label, type, value: (row: Row) => { const v = row.cells[column.key]; return typeof v === 'string' && isEventType(v) ? eventLabel(v) : v; }, href: index === 0 ? (row: Row) => row.link : row => row.links?.[column.key] ?? null, width };
    }),
);

function apply(): void {
    const query: Record<string, string> = Object.fromEntries(new URLSearchParams(window.location.search).entries());
    if (props.filter === 'as_of') query.as_of = filters.as_of;
    else Object.assign(query, { from: filters.from, to: filters.to }, props.filter === 'range_by' ? { by: filters.by } : {});
    router.get(props.baseUrl ?? `/reports/${props.report}`, query, { preserveState: true });
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
        <DataTable id="report" v-model:active="active" :label="title" :columns="columns" :rows="rows" :row-key="(r) => r.__key" :currency="currency" :url-sync="false" empty-text="Nothing in this period." @open="open">
            <template #toolbar>
                <h1 class="mr-2 shrink-0 text-section font-semibold">{{ title }}</h1>
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
                <nav v-if="related?.length" class="ml-2 flex items-center gap-3 text-ui" aria-label="Other statements for this period">
                    <Link v-for="statement in related" :key="statement.href" :href="statement.href" class="text-accent-text hover:underline" @click="drillFrom(title)">{{ statement.label }}</Link>
                </nav>
            </template>
        </DataTable>
        </div>
        <dl v-if="Object.keys(totals).length" class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-line bg-surface-2 px-4 py-2 text-ui">
            <div v-for="(amount, label) in totals" :key="label" class="flex items-baseline gap-2">
                <dt class="text-ink-2">{{ String(label).replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()) }}</dt>
                <dd class="tabular-nums font-medium">{{ formatMoney(amount) }}</dd>
            </div>
        </dl>
        <div v-if="summaries?.length" class="flex max-h-[40vh] flex-wrap gap-x-8 gap-y-3 overflow-auto border-t border-line px-4 py-3" @click.capture="(e) => (e.target as HTMLElement).closest('a[href]') && drillFrom(title)">
            <section v-for="summary in summaries" :key="summary.title" class="min-w-0">
                <h2 class="mb-1 text-ui font-medium text-ink">{{ summary.title }}</h2>
                <table class="text-ui">
                    <thead>
                        <tr class="border-b border-line text-ink-2">
                            <th v-for="(column, index) in summary.columns" :key="column.key" scope="col" class="py-1 pr-6 font-normal" :class="index === 0 ? 'text-left' : 'text-right'">{{ column.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, rowIndex) in summary.rows" :key="rowIndex" class="border-b border-line last:border-b-0">
                            <td v-for="(column, index) in summary.columns" :key="column.key" class="py-1 pr-6" :class="index === 0 ? 'text-left' : 'text-right tabular-nums'">
                                <Link v-if="index === 0 && row.link" :href="row.link" class="text-accent-text hover:underline">{{ row.cells[column.key] }}</Link>
                                <template v-else>{{ isMoney(row.cells[column.key]) ? formatMoney(String(row.cells[column.key])) : row.cells[column.key] }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
