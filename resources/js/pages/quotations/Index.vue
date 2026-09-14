<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** Phase 3 slice R4: quotations, newest first — drafts to finish, issued quotations to follow up before they expire. */
interface QuotationRow {
    id: string; number: string | null; status: string; customer: string | null; product: string; producer: string | null; producer_eligible: boolean | null;
    inception: string; valid_until: string | null; sum_insured: string | null; gross_premium: string | null; created_at: string; created_by: string;
}
const props = defineProps<{ quotations: QuotationRow[]; statuses: string[]; currency: string; canCreate: boolean }>();

const active = ref<string | null>(null);
const eligibility = (q: QuotationRow) => (q.producer === null ? 'Direct' : q.producer_eligible ? 'Licensed' : 'Not licensed for this class');
const columns: DataColumn<QuotationRow>[] = [
    { id: 'number', header: 'Quotation', value: (q) => q.number ?? 'Draft', href: (q) => `/quotations/${q.id}`, width: 170 },
    { id: 'customer', header: 'Customer', value: (q) => q.customer ?? 'No customer yet', width: 200 },
    { id: 'product', header: 'Product', value: (q) => q.product, width: 200 },
    { id: 'producer', header: 'Producer', value: (q) => q.producer ?? 'Direct', width: 110 },
    { id: 'sum_insured', header: 'Sum insured', type: 'money', value: (q) => q.sum_insured },
    { id: 'gross', header: 'Gross premium', type: 'money', value: (q) => q.gross_premium, total: true },
    { id: 'valid_until', header: 'Valid until', type: 'date', value: (q) => q.valid_until },
    { id: 'status', header: 'Status', type: 'status', value: (q) => q.status, filterOptions: props.statuses },
];
</script>

<template>
    <AppLayout help="quotes" title="Quotes" fill>
        <QueueView
            id="quotations"
            v-model:active="active"
            title="Quotes"
            :columns="columns"
            :rows="quotations"
            :row-key="(q) => q.id"
            :currency="currency"
            empty-text="No quotations yet."
            :empty-action="canCreate ? { label: 'New quote', href: '/quotations/create' } : { label: 'Open policies', href: '/policies' }"
            :action="canCreate ? { label: 'New quote', href: '/quotations/create' } : null"
            :inspector-title="(q) => q.number ?? 'Draft quotation'"
            :inspector-subtitle="(q) => q.customer ?? undefined"
        >
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Status' },
                        { label: 'Product', value: row.product },
                        { label: 'Cover starts', value: formatDate(row.inception) },
                        { label: 'Sum insured', value: row.sum_insured ? `${formatMoney(row.sum_insured)} ${currency}` : 'Not rated yet', num: true },
                        { label: 'Gross premium', value: row.gross_premium ? `${formatMoney(row.gross_premium)} ${currency}` : 'Not rated yet', num: true },
                        { label: 'Valid until', value: row.valid_until ? formatDate(row.valid_until) : 'Set when issued' },
                        { label: 'Producer', value: `${row.producer ?? 'Direct'} · ${eligibility(row)}` },
                        { label: 'Prepared by', value: `${row.created_by}, ${formatDate(row.created_at)}` },
                    ]"
                >
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/quotations/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the quotation</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
