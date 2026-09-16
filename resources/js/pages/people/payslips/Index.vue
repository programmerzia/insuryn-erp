<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney, formatMonth } from '@/lib/format';
import { serverPage, type Paginated } from '@/lib/paging';
const currency = useEntityCurrency();

/** Addendum §B.10.1 payslips per run and employee, each printable as a PDF in English or Bangla. */
interface PayslipRow { id: string; number: string | null; run_id: string; period: string; status: string; employee_id: string; code: string; name: string; gross: string; tax: string; pf: string; net: string }
const props = defineProps<{ runId: string | null; runs: { id: string; label: string }[]; payslips: Paginated<PayslipRow> }>();

const active = ref<string | null>(null);
const run = ref(props.runId ?? '');
const columns: DataColumn<PayslipRow>[] = [
    { id: 'period', header: 'Month', value: (p) => formatMonth(p.period, 'long'), href: (p) => `/people/payroll/${p.run_id}`, width: 130 },
    { id: 'number', header: 'Payslip', value: (p) => p.number ?? 'Preview', width: 150, muted: true },
    { id: 'code', header: 'Code', value: (p) => p.code, href: (p) => `/people/employees/${p.employee_id}`, width: 90 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 170 },
    { id: 'gross', header: 'Gross', type: 'money', value: (p) => p.gross, total: true },
    { id: 'pf', header: 'PF', type: 'money', value: (p) => p.pf, total: true },
    { id: 'tax', header: 'Tax', type: 'money', value: (p) => p.tax, total: true },
    { id: 'net', header: 'Net pay', type: 'money', value: (p) => p.net, total: true },
    { id: 'status', header: 'Run', type: 'status', value: (p) => p.status, filterOptions: ['preview', 'posted', 'paid'] },
];
function choose(value: string): void {
    router.get('/people/payslips', value === '' ? {} : { run: value }, { preserveState: false });
}
</script>

<template>
    <AppLayout title="Payslips" fill>
        <QueueView
            id="people-payslips"
            v-model:active="active"
            title="Payslips"
            :columns="columns"
            :rows="payslips.data"
            :page="serverPage(payslips)"
            :row-key="(p) => p.id"
            :currency="currency"
            :url-sync="false"
            :empty-text="runId ? 'No payslips in this run.' : 'No payslips yet: they appear when a payroll is calculated.'"
            :empty-action="runId ? { label: 'Show every payslip', href: '/people/payslips' } : { label: 'Open payroll runs', href: '/people/payroll' }"
            :inspector-title="(p) => `${p.code} · ${p.name}`"
            :inspector-subtitle="(p) => `${formatMonth(p.period, 'long')} · ${p.number ?? 'Preview'}`"
        >
            <template #toolbar>
                <div class="ml-2 flex items-center gap-2">
                    <label class="sr-only" for="payslip_run">Payroll run</label>
                    <SelectInput id="payslip_run" :model-value="run" class="w-64" placeholder="Every run" :options="runs.map((r) => ({ value: r.id, label: r.label }))" @update:model-value="(v) => choose(String(v ?? ''))" />
                </div>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Gross', value: formatMoney(row.gross), num: true }, { label: 'Provident fund', value: formatMoney(row.pf), num: true },
                    { label: 'Tax deducted', value: formatMoney(row.tax), num: true }, { label: 'Net pay', value: `${formatMoney(row.net, currency)}`, num: true }, { label: 'Run' }]">
                    <template #Run><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div class="mt-4 flex gap-4 text-ui">
                    <a :href="`/people/payslips/${row.id}/pdf`" target="_blank" class="text-accent-text hover:underline">Open PDF</a>
                    <a :href="`/people/payslips/${row.id}/pdf?locale=bn`" target="_blank" class="text-accent-text hover:underline">বাংলা PDF</a>
                    <Link :href="`/people/employees/${row.employee_id}`" class="text-accent-text hover:underline">Open the employee</Link>
                </div>
            </template>
        </QueueView>
    </AppLayout>
</template>
