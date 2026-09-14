<script setup lang="ts">
import { ref } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';

interface Notice { id: string; policy_id: string; policy_number: string | null; payer: string; level: number; days_overdue: number; outstanding: string; issued_on: string }
defineProps<{ from: string; to: string; notices: Notice[] }>();

const active = ref<string | null>(null);
const columns: DataColumn<Notice>[] = [
    { id: 'issued', header: 'Issued', type: 'date', value: (n) => n.issued_on },
    { id: 'policy', header: 'Policy', value: (n) => n.policy_number, href: (n) => `/policies/${n.policy_id}`, width: 160 },
    { id: 'payer', header: 'Payer', value: (n) => n.payer, width: 200 },
    { id: 'level', header: 'Reminder', value: (n) => (n.level === 1 ? 'First' : n.level === 2 ? 'Second' : `Level ${n.level}`), width: 100, filterOptions: ['First', 'Second'] },
    { id: 'days', header: 'Days overdue', type: 'number', value: (n) => n.days_overdue, width: 110 },
    { id: 'outstanding', header: 'Outstanding', type: 'money', value: (n) => n.outstanding, total: true },
];
</script>

<template>
    <AppLayout title="Payment reminders" fill>
        <QueueView id="dunning" v-model:active="active" title="Payment reminders" :columns="columns" :rows="notices" :row-key="(n) => n.id" currency="BDT" empty-text="No reminders were issued in this period." :empty-action="{ label: 'Record a receipt', href: '/receipts/create' }">
            <template #toolbar><DateRangeFilter url="/dunning" :from="from" :to="to" /></template>
        </QueueView>
    </AppLayout>
</template>
