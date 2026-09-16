<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import Field from '@/components/forms/Field.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { usePermissions } from '@/lib/permissions';
const currency = useEntityCurrency();

/**
 * Cheque register. Gap fix GA-14: a cheque waits in clearing until the bank credits it; "Clear" moves it into the bank (with the journal preview), and a
 * bounce is recorded on the receipt page.
 */
interface Row { receipt_id: string; receipt_number: string; cheque_no: string; cheque_bank: string; cheque_date: string; value_date: string; amount: string; state: string; bounced_on: string | null; bounce_reason: string | null; cleared_on: string | null }
const props = defineProps<{ from: string; to: string; today: string; register: { rows: Row[]; totals: { presented: string; bounced: string; in_clearing: string; cleared: string } } }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const clearedOn = ref(props.today);
const confirm = useJournalConfirm();
const stateWords: Record<string, string> = { in_clearing: 'In clearing', cleared: 'Cleared', bounced: 'Bounced', presented: 'Paid in' };
const columns: DataColumn<Row>[] = [
    { id: 'cheque', header: 'Cheque', value: (r) => r.cheque_no, width: 110 },
    { id: 'bank', header: 'Drawee bank', value: (r) => r.cheque_bank, width: 160 },
    { id: 'cheque_date', header: 'Cheque date', type: 'date', value: (r) => r.cheque_date },
    { id: 'receipt', header: 'Receipt', value: (r) => r.receipt_number, href: (r) => `/receipts/${r.receipt_id}`, width: 150 },
    { id: 'value_date', header: 'Received', type: 'date', value: (r) => r.value_date },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'state', header: 'State', type: 'status', value: (r) => r.state, filterOptions: ['in_clearing', 'cleared', 'bounced', 'presented'] },
    { id: 'settled', header: 'Cleared or bounced', type: 'date', value: (r) => r.cleared_on ?? r.bounced_on },
    { id: 'reason', header: 'Bounce reason', value: (r) => r.bounce_reason, width: 200, muted: true },
];
const clearable = (r: Row) => r.state === 'in_clearing' && can('receipt.allocate');

function clear(r: Row): void {
    void confirm.request(`/receipts/${r.receipt_id}/clear`, { cleared_on: clearedOn.value }, `Clear cheque ${r.cheque_no} into the bank?`, `Clear ${formatMoney(r.amount, currency)}`);
}
</script>

<template>
    <AppLayout help="cheques" title="Cheque register" fill>
        <QueueView
            id="cheques"
            v-model:active="active"
            title="Cheque register"
            :columns="columns"
            :rows="register.rows"
            :row-key="(r) => r.receipt_id"
            :currency="currency"
            empty-text="No cheques in this period."
            :empty-action="{ label: 'Record a receipt', href: '/receipts/create' }"
            :inspector-title="(r) => `Cheque ${r.cheque_no}`"
            :inspector-subtitle="(r) => `${r.cheque_bank} · ${stateWords[r.state] ?? r.state}`"
            :primary-label="(r) => (clearable(r) ? 'Cheque cleared' : undefined)"
            @primary="clear"
        >
            <template #toolbar>
                <DateRangeFilter url="/cheques" :from="from" :to="to" />
                <span class="ml-3 text-ui text-ink-2">In clearing <span class="tabular-nums text-ink">{{ formatMoney(register.totals.in_clearing) }}</span> · cleared <span class="tabular-nums text-ink">{{ formatMoney(register.totals.cleared) }}</span> · bounced <span class="tabular-nums text-ink">{{ formatMoney(register.totals.bounced) }}</span></span>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Receipt', value: row.receipt_number }, { label: 'Amount', value: `${formatMoney(row.amount, currency)}`, num: true }, { label: 'Received', value: formatDate(row.value_date) },
                    { label: 'Cleared', value: formatDate(row.cleared_on) }, { label: 'Bounced', value: row.bounced_on ? `${formatDate(row.bounced_on)}: ${row.bounce_reason}` : null }]" />
                <p v-if="row.state === 'in_clearing'" class="mt-3 text-ui text-ink-2">The money is in cheques in clearing until the bank credits it. If the bank returns the cheque, record the bounce on the receipt.</p>
                <div v-if="clearable(row)" class="mt-4 grid gap-3 border-t border-line pt-4">
                    <Field id="cleared_on" label="Cleared on" hint="The day the bank credited the cheque, as on the statement."><DateInput v-model="clearedOn" /></Field>
                </div>
            </template>
        </QueueView>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
