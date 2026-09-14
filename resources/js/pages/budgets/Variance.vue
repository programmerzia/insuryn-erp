<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { drillFrom } from '@/lib/drill';
import { formatMoney } from '@/lib/format';

/**
 * Design addendum v2 §B.8.1 variance report: actual against the approved budget for a month and the year to date, by account group and branch. Green is
 * favourable (spent less, or earned more), red unfavourable. Each account opens its ledger activity for the month.
 */
interface Row { account_id: string; account: string; group: string; branch: string; budget: string; actual: string; variance: string; variance_pct: string | null; favourable: boolean;
    ytd_budget: string; ytd_actual: string; ytd_variance: string; ytd_variance_pct: string | null; ytd_favourable: boolean; activity: string }
const props = defineProps<{
    budget: { id: string; name: string; version: number } | null;
    filters: { year: string; period: string; branch: string };
    periodLabel: string;
    years: { value: string; label: string }[];
    periods: { value: string; label: string }[];
    branches: { value: string; label: string }[];
    rows: Row[];
    totals: { expense_budget: string; expense_actual: string; expense_ytd_budget: string; expense_ytd_actual: string };
}>();
const filters = reactive({ ...props.filters });
const groups = computed(() => {
    const map = new Map<string, Row[]>();
    for (const row of props.rows) map.set(row.group, [...(map.get(row.group) ?? []), row]);
    return [...map.entries()];
});
const tone = (favourable: boolean, variance: string) => (variance === '0.00' ? 'text-ink-2' : favourable ? 'text-ok' : 'text-danger');
const exportQuery = computed(() => new URLSearchParams({ year: props.filters.year, period: props.filters.period, ...(props.filters.branch ? { branch: props.filters.branch } : {}) }).toString());
function set(key: 'year' | 'period' | 'branch', value: string | undefined): void {
    filters[key] = value ?? '';
    router.get('/budgets/variance', { year: filters.year, period: filters.period, ...(filters.branch ? { branch: filters.branch } : {}) }, { preserveState: true });
}
</script>

<template>
    <AppLayout help="budgets" title="Budget variance" fill>
        <div class="border-b border-line px-4 pt-2"><Breadcrumb :base="[{ label: 'Budgets', href: '/budgets' }]" /></div>
        <header class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-ui">
            <h1 class="mr-2 text-section font-semibold">Budget variance</h1>
            <SelectInput :model-value="filters.year" :options="years" class="w-auto" aria-label="Fiscal year" @update:model-value="set('year', $event)" />
            <SelectInput :model-value="filters.period" :options="periods" class="w-auto" aria-label="Month" @update:model-value="set('period', $event)" />
            <SelectInput :model-value="filters.branch" :options="branches" placeholder="All branches" class="w-auto" aria-label="Branch" @update:model-value="set('branch', $event)" />
            <span v-if="budget" class="text-ink-2">against <Link :href="`/budgets/${budget.id}`" class="text-accent-text hover:underline">{{ budget.name }} v{{ budget.version }}</Link></span>
            <span v-else class="text-danger">No approved budget for this year: actuals only.</span>
            <div class="ml-auto flex items-center gap-1">
                <a :href="`/budgets/variance/export?${exportQuery}&format=csv`" class="inline-flex h-8 items-center rounded-control px-2 text-accent-text hover:bg-surface-2" download>Export CSV</a>
                <a :href="`/budgets/variance/export?${exportQuery}&format=xlsx`" class="inline-flex h-8 items-center rounded-control px-2 text-accent-text hover:bg-surface-2" download>XLSX</a>
            </div>
        </header>
        <div class="min-h-0 flex-1 overflow-auto">
            <table class="w-full border-separate border-spacing-0 text-dense">
                <thead class="sticky top-0 z-10 bg-surface-2 text-ink-2">
                    <tr class="h-7">
                        <th colspan="2" class="border-b border-line" /><th colspan="4" class="border-b border-l border-line px-3 text-center font-medium">{{ periodLabel }}</th><th colspan="4" class="border-b border-l border-line px-3 text-center font-medium">Year to date</th>
                    </tr>
                    <tr class="h-(--row-h)">
                        <th class="border-b border-line px-3 text-left font-medium">Account</th><th class="border-b border-line px-3 text-left font-medium">Branch</th>
                        <th class="border-b border-l border-line px-3 text-right font-medium">Budget (BDT)</th><th class="border-b border-line px-3 text-right font-medium">Actual</th><th class="border-b border-line px-3 text-right font-medium">Variance</th><th class="border-b border-line px-3 text-right font-medium">%</th>
                        <th class="border-b border-l border-line px-3 text-right font-medium">Budget</th><th class="border-b border-line px-3 text-right font-medium">Actual</th><th class="border-b border-line px-3 text-right font-medium">Variance</th><th class="border-b border-line px-3 text-right font-medium">%</th>
                    </tr>
                </thead>
                <tbody v-for="[group, items] in groups" :key="group">
                    <tr class="h-7 bg-surface-2"><td colspan="10" class="border-b border-line px-3 font-medium">{{ group }}</td></tr>
                    <tr v-for="row in items" :key="`${row.account_id}-${row.branch}`" class="h-(--row-h) hover:bg-surface-2">
                        <td class="border-b border-line px-3"><Link :href="row.activity" class="text-accent-text hover:underline" @click="drillFrom('Budget variance')">{{ row.account }}</Link></td>
                        <td class="border-b border-line px-3">{{ row.branch }}</td>
                        <td class="num border-b border-l border-line px-3">{{ formatMoney(row.budget) }}</td><td class="num border-b border-line px-3">{{ formatMoney(row.actual) }}</td>
                        <td class="num border-b border-line px-3" :class="tone(row.favourable, row.variance)">{{ formatMoney(row.variance) }}</td>
                        <td class="num border-b border-line px-3" :class="tone(row.favourable, row.variance)">{{ row.variance_pct === null ? '—' : `${row.variance_pct}%` }}</td>
                        <td class="num border-b border-l border-line px-3">{{ formatMoney(row.ytd_budget) }}</td><td class="num border-b border-line px-3">{{ formatMoney(row.ytd_actual) }}</td>
                        <td class="num border-b border-line px-3" :class="tone(row.ytd_favourable, row.ytd_variance)">{{ formatMoney(row.ytd_variance) }}</td>
                        <td class="num border-b border-line px-3" :class="tone(row.ytd_favourable, row.ytd_variance)">{{ row.ytd_variance_pct === null ? '—' : `${row.ytd_variance_pct}%` }}</td>
                    </tr>
                </tbody>
                <tbody v-if="rows.length === 0"><tr><td colspan="10" class="px-3 py-8 text-center text-ui text-ink-2">Nothing budgeted or posted for this month.</td></tr></tbody>
            </table>
        </div>
        <dl class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-line bg-surface-2 px-4 py-2 text-ui">
            <div class="flex gap-2"><dt class="text-ink-2">Expenses this month: budget</dt><dd class="num font-medium">{{ formatMoney(totals.expense_budget) }}</dd><dt class="text-ink-2">actual</dt><dd class="num font-medium">{{ formatMoney(totals.expense_actual) }}</dd></div>
            <div class="flex gap-2"><dt class="text-ink-2">Year to date: budget</dt><dd class="num font-medium">{{ formatMoney(totals.expense_ytd_budget) }}</dd><dt class="text-ink-2">actual</dt><dd class="num font-medium">{{ formatMoney(totals.expense_ytd_actual) }}</dd></div>
        </dl>
    </AppLayout>
</template>
