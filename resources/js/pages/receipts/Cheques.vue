<script setup lang="ts">
import { ref } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

interface Row { receipt_id: string; receipt_number: string; cheque_no: string; cheque_bank: string; cheque_date: string; value_date: string; amount: string; state: string; bounced_on: string | null; bounce_reason: string | null }
defineProps<{ from: string; to: string; register: { rows: Row[]; totals: { presented: string; bounced: string } } }>();

const active = ref<string | null>(null);
const columns: DataColumn<Row>[] = [
    { id: 'cheque', header: 'Cheque', value: (r) => r.cheque_no, width: 110 },
    { id: 'bank', header: 'Drawee bank', value: (r) => r.cheque_bank, width: 160 },
    { id: 'cheque_date', header: 'Cheque date', type: 'date', value: (r) => r.cheque_date },
    { id: 'receipt', header: 'Receipt', value: (r) => r.receipt_number, href: (r) => `/receipts/${r.receipt_id}`, width: 150 },
    { id: 'value_date', header: 'Value date', type: 'date', value: (r) => r.value_date },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'state', header: 'State', type: 'status', value: (r) => r.state, filterOptions: ['presented', 'bounced'] },
    { id: 'reason', header: 'Bounce reason', value: (r) => r.bounce_reason, width: 200, muted: true },
];
</script>

<template>
    <AppLayout title="Cheque register" fill>
        <QueueView id="cheques" v-model:active="active" title="Cheque register" :columns="columns" :rows="register.rows" :row-key="(r) => r.receipt_id" currency="BDT" empty-text="No cheques in this period.">
            <template #toolbar>
                <DateRangeFilter url="/cheques" :from="from" :to="to" />
                <span class="ml-3 text-ui text-ink-2">Presented <span class="tabular-nums text-ink">{{ formatMoney(register.totals.presented) }}</span> · bounced <span class="tabular-nums text-ink">{{ formatMoney(register.totals.bounced) }}</span></span>
            </template>
        </QueueView>
    </AppLayout>
</template>
