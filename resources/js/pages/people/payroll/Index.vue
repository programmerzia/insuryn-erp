<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';

/** Addendum §B.10.11 payroll runs queue: one regular run a month, preview → posted → paid. */
interface RunRow { id: string; number: string | null; period: string; status: string; employees: number; gross: string; tax: string; pf: string; commission: string; net: string; posted_on: string | null; paid_on: string | null }
const props = defineProps<{ runs: RunRow[]; months: string[]; can: { prepare: boolean } }>();

const active = ref<string | null>(null);
const month = ref(props.months.find((m) => !props.runs.some((r) => r.period === m)) ?? props.months[0] ?? '');
const columns: DataColumn<RunRow>[] = [
    { id: 'period', header: 'Month', value: (r) => formatMonth(r.period, 'long'), href: (r) => `/people/payroll/${r.id}`, width: 140 },
    { id: 'number', header: 'Run', value: (r) => r.number ?? 'Preview', width: 150, muted: true },
    { id: 'employees', header: 'Employees', type: 'number', value: (r) => r.employees, width: 90 },
    { id: 'gross', header: 'Gross', type: 'money', value: (r) => r.gross, total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (r) => r.commission, total: true },
    { id: 'tax', header: 'Tax deducted', type: 'money', value: (r) => r.tax, total: true },
    { id: 'pf', header: 'Provident fund', type: 'money', value: (r) => r.pf, total: true },
    { id: 'net', header: 'Net pay', type: 'money', value: (r) => r.net, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['preview', 'posted', 'paid'] },
];
function calculate(): void {
    router.post('/people/payroll', { period: month.value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Payroll runs" fill>
        <QueueView
            id="people-payroll-runs"
            v-model:active="active"
            title="Payroll runs"
            :columns="columns"
            :rows="runs"
            :row-key="(r) => r.id"
            currency="BDT"
            :url-sync="false"
            empty-text="No payroll yet: calculate the first month."
            :empty-action="can.prepare ? { label: 'Calculate payroll' } : null"
            :inspector-title="(r) => `Payroll ${formatMonth(r.period, 'long')}`"
            :inspector-subtitle="(r) => r.number ?? 'Preview'"
            @action="calculate"
        >
            <template #toolbar>
                <div v-if="can.prepare" class="ml-2 flex items-center gap-2">
                    <label class="sr-only" for="payroll_month">Month</label>
                    <SelectInput id="payroll_month" v-model="month" class="w-44" :options="months.map((m) => ({ value: m, label: formatMonth(m, 'long') }))" />
                    <Button size="sm" @click="calculate">Calculate payroll</Button>
                </div>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Employees', value: row.employees, num: true }, { label: 'Gross', value: formatMoney(row.gross), num: true },
                    { label: 'Commission through payroll', value: formatMoney(row.commission), num: true }, { label: 'Tax deducted', value: formatMoney(row.tax), num: true },
                    { label: 'Provident fund', value: formatMoney(row.pf), num: true }, { label: 'Net pay', value: `${formatMoney(row.net)} BDT`, num: true },
                    { label: 'Posted', value: row.posted_on ? formatDate(row.posted_on) : '—' }, { label: 'Paid', value: row.paid_on ? formatDate(row.paid_on) : '—' }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/people/payroll/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the run</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
