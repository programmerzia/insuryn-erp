<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { type Paginated, serverPage } from '@/lib/paging';

/** Payables → Supplier bills (addendum v2 §B.4): every bill, with the views finance works from — awaiting approval, due this week, overdue. */
interface BillRow { id: string; number: string; supplier: string; reference: string; bill_date: string; due_date: string; branch: string; gross: string; payable: string; outstanding: string; status: string; description: string | null }
const props = defineProps<{ bills: Paginated<BillRow>; view: string; statuses: string[]; canEnter: boolean }>();

const active = ref<string | null>(null);
const views = [
    { value: 'all', label: 'All bills' },
    { value: 'awaiting_approval', label: 'Awaiting approval' },
    { value: 'open', label: 'Unpaid' },
    { value: 'due_this_week', label: 'Due this week' },
    { value: 'overdue', label: 'Overdue' },
];
const columns: DataColumn<BillRow>[] = [
    { id: 'number', header: 'Bill', value: (b) => b.number, href: (b) => `/payables/bills/${b.id}`, width: 170 },
    { id: 'supplier', header: 'Supplier', value: (b) => b.supplier, width: 220 },
    { id: 'reference', header: 'Invoice', value: (b) => b.reference, width: 130, muted: true },
    { id: 'bill_date', header: 'Bill date', type: 'date', value: (b) => b.bill_date },
    { id: 'due_date', header: 'Due', type: 'date', value: (b) => b.due_date },
    { id: 'branch', header: 'Branch', value: (b) => b.branch, width: 80, muted: true },
    { id: 'gross', header: 'Gross', type: 'money', value: (b) => b.gross, total: true },
    { id: 'payable', header: 'Payable', type: 'money', value: (b) => b.payable, total: true },
    { id: 'outstanding', header: 'Outstanding', type: 'money', value: (b) => b.outstanding, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (b) => b.status, filterOptions: props.statuses },
];
function show(view: string): void {
    router.get('/payables/bills', view === 'all' ? {} : { view }, { preserveState: false });
}
</script>

<template>
    <AppLayout help="payables" title="Supplier bills" fill>
        <QueueView
            id="supplier-bills"
            v-model:active="active"
            title="Supplier bills"
            :columns="columns"
            :rows="bills.data"
            :page="serverPage(bills)"
            :row-key="(b) => b.id"
            currency="BDT"
            :url-sync="false"
            empty-text="No supplier bills in this view."
            :empty-action="canEnter && view === 'all' ? { label: 'Enter a bill', href: '/payables/bills/create' } : null"
            :action="canEnter ? { label: 'Enter a bill', href: '/payables/bills/create' } : null"
            :inspector-title="(b) => b.number"
            :inspector-subtitle="(b) => `${b.supplier} · ${b.reference}`"
        >
            <template #toolbar>
                <div class="ml-2 flex flex-wrap gap-1" role="group" aria-label="Views">
                    <button v-for="v in views" :key="v.value" type="button" class="h-8 rounded-control px-2 text-ui" :class="view === v.value ? 'bg-accent-soft font-medium text-ink' : 'text-ink-2 hover:bg-surface-2'" @click="show(v.value)">{{ v.label }}</button>
                </div>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Payable', value: `${formatMoney(row.payable)} BDT`, num: true }, { label: 'Outstanding', value: `${formatMoney(row.outstanding)} BDT`, num: true }, { label: 'Due', value: formatDate(row.due_date) }, { label: 'Branch', value: row.branch }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <p v-if="row.description" class="mt-3 text-ui text-ink-2">{{ row.description }}</p>
                <Link :href="`/payables/bills/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the bill</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
