<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { type Paginated, serverPage } from '@/lib/paging';

/** Payables → Payment runs (addendum v2 §B.4): prepared by the accountant, approved by the finance manager, released by the CFO. */
interface RunRow { id: string; number: string; pay_date: string; status: string; total: string; bills: number; bank: string; prepared_by: string }
const props = defineProps<{ runs: Paginated<RunRow>; statuses: string[]; canPrepare: boolean }>();

const active = ref<string | null>(null);
const columns: DataColumn<RunRow>[] = [
    { id: 'number', header: 'Payment run', value: (r) => r.number, href: (r) => `/payables/payment-runs/${r.id}`, width: 170 },
    { id: 'pay_date', header: 'Pay date', type: 'date', value: (r) => r.pay_date },
    { id: 'bank', header: 'Paid from', value: (r) => r.bank, width: 200, muted: true },
    { id: 'bills', header: 'Bills', type: 'number', value: (r) => r.bills, width: 80 },
    { id: 'total', header: 'Total', type: 'money', value: (r) => r.total, total: true },
    { id: 'prepared_by', header: 'Prepared by', value: (r) => r.prepared_by, width: 160, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: props.statuses },
];
</script>

<template>
    <AppLayout help="payables" title="Payment runs" fill>
        <QueueView
            id="payment-runs"
            v-model:active="active"
            title="Payment runs"
            :columns="columns"
            :rows="runs.data"
            :page="serverPage(runs)"
            :row-key="(r) => r.id"
            currency="BDT"
            empty-text="No payment runs yet: pay the bills that fall due."
            :action="canPrepare ? { label: 'New payment run', href: '/payables/payment-runs/create' } : null"
            :hint="canPrepare ? null : 'Only the accountant can prepare a payment run.'"
            :inspector-title="(r) => r.number"
            :inspector-subtitle="(r) => `${r.bills} bills · ${r.bank}`"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Total', value: `${formatMoney(row.total)} BDT`, num: true }, { label: 'Pay date', value: formatDate(row.pay_date) }, { label: 'Prepared by', value: row.prepared_by }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/payables/payment-runs/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the run</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
