<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

interface Item { id: string; receipt_id: string; receipt_number: string; reference: string | null; aged_since: string; days: number; open: string }
const props = defineProps<{ asOf: string; ageing: { buckets: Record<string, string>; total: string; items: Item[] }; installments: { id: string; label: string; outstanding: string }[] }>();

const active = ref<string | null>(null);
const bucketOf = (days: number) => (days <= 30 ? '0–30 days' : days <= 60 ? '31–60 days' : days <= 90 ? '61–90 days' : 'Over 90 days');
const columns: DataColumn<Item>[] = [
    { id: 'receipt', header: 'Receipt', value: (i) => i.receipt_number, href: (i) => `/receipts/${i.receipt_id}/allocate`, pinTitle: (i) => `Allocate ${i.receipt_number}`, width: 160 },
    { id: 'reference', header: 'Reference', value: (i) => i.reference, width: 220, muted: true },
    { id: 'since', header: 'Waiting since', type: 'date', value: (i) => i.aged_since },
    { id: 'days', header: 'Days', type: 'number', value: (i) => i.days, width: 72 },
    { id: 'age', header: 'Age', value: (i) => bucketOf(i.days), width: 120, filterOptions: ['0–30 days', '31–60 days', '61–90 days', 'Over 90 days'] },
    { id: 'open', header: 'Unallocated', type: 'money', value: (i) => i.open, total: true },
];
void props;
</script>

<template>
    <AppLayout title="Suspense" fill>
        <QueueView
            id="suspense"
            v-model:active="active"
            title="Suspense"
            :columns="columns"
            :rows="ageing.items"
            :row-key="(i) => i.id"
            currency="BDT"
            empty-text="No unallocated receipts. Import a bank statement to find more."
            :inspector-title="(i) => i.receipt_number"
            :inspector-subtitle="(i) => i.reference ?? 'No reference'"
        >
            <template #toolbar>
                <DateRangeFilter url="/suspense" :as-of="asOf" />
                <span class="ml-3 text-ui text-ink-2">
                    <template v-for="(amount, bucket, index) in ageing.buckets" :key="bucket"><span v-if="index" aria-hidden="true"> · </span>{{ bucket }} days <span class="tabular-nums text-ink">{{ formatMoney(amount) }}</span></template>
                </span>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Unallocated', value: `${formatMoney(row.open)} BDT`, num: true }, { label: 'Waiting since', value: formatDate(row.aged_since) }, { label: 'Days waiting', value: row.days }]" />
                <Link :href="`/receipts/${row.receipt_id}/allocate`" class="mt-4 inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Open the allocation workbench</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
