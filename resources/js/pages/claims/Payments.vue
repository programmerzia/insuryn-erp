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

/** Gap audit GA-26: the claim payments queue. Payments still to approve, request or release come first; each opens its claim, where it is released. */
interface PaymentRow { id: string; status: string; approved_on: string; paid_on: string | null; amount: string; claim_id: string; claim_number: string; policy_number: string | null; payee: string }
const props = defineProps<{ statuses: string[]; payments: Paginated<PaymentRow> }>();

const active = ref<string | null>(null);
const columns: DataColumn<PaymentRow>[] = [
    { id: 'claim', header: 'Claim', value: (p) => p.claim_number, href: (p) => `/claims/${p.claim_id}`, width: 160 },
    { id: 'policy', header: 'Policy', value: (p) => p.policy_number, width: 170, muted: true },
    { id: 'payee', header: 'Payee', value: (p) => p.payee, width: 200 },
    { id: 'approved_on', header: 'Approved', type: 'date', value: (p) => p.approved_on },
    { id: 'paid_on', header: 'Paid', type: 'date', value: (p) => p.paid_on },
    { id: 'amount', header: 'Amount', type: 'money', value: (p) => p.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status, filterOptions: props.statuses },
];
</script>

<template>
    <AppLayout help="claims" title="Claim payments" fill>
        <QueueView
            id="claim-payments"
            v-model:active="active"
            title="Claim payments"
            :columns="columns"
            :rows="payments.data"
            :row-key="(p) => p.id"
            :page="serverPage(payments)"
            currency="BDT"
            empty-text="No claim payment has been approved yet."
            :empty-action="{ label: 'Open claims', href: '/claims' }"
            :inspector-title="(p) => p.claim_number"
            :inspector-subtitle="(p) => p.payee"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Payee', value: row.payee }, { label: 'Approved on', value: formatDate(row.approved_on) }, { label: 'Amount', value: `${formatMoney(row.amount)} BDT`, num: true }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/claims/${row.claim_id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the claim to release it</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
