<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
const currency = useEntityCurrency();

interface RefundRow { id: string; policy_number: string | null; amount: string; reason: string; status: string; requested_at: string; decision_reason: string | null }
const props = defineProps<{
    refunds: RefundRow[];
    /** GA-40: every refund, when `refunds` holds only the latest. */
    refundsTotal?: number;
    /** GA-40: how many policies have money to refund (the request drawer looks them up). */
    refundableCount: number;
    can: { request: boolean; release: boolean };
    /** GA-01: opened from a cancellation, the request starts with that policy and what is still refundable on it. */
    prefill?: { policy_id: string; label: string; amount: string; reason: string } | null;
}>();

const active = ref<string | null>(null);
const requesting = ref(Boolean(props.prefill && props.can.request));
const requestForm = useForm({ policy_id: props.prefill?.policy_id ?? '', amount: props.prefill?.amount ?? '', reason: props.prefill?.reason ?? '' });
// Gap fix GA-19: a refund is paid today unless changed; the request starts with what is still refundable on the chosen policy (GA-01's prefill kept).
const today = useBusinessToday();
const paidOn = ref(today);
let lastRefundable = props.prefill?.amount ?? '';
function policyPicked(result: LookupResult | null): void {
    if (requestForm.amount === '' || requestForm.amount === lastRefundable) requestForm.amount = result?.amount ?? '';
    lastRefundable = result?.amount ?? '';
}
const rejectReason = ref('');
const confirm = useJournalConfirm();
const columns: DataColumn<RefundRow>[] = [
    { id: 'policy', header: 'Policy', value: (r) => r.policy_number, width: 160 },
    { id: 'requested', header: 'Requested', type: 'date', value: (r) => r.requested_at },
    { id: 'reason', header: 'Reason', value: (r) => r.reason, width: 240, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['requested', 'released', 'rejected'] },
];
const releasable = (row: RefundRow) => row.status === 'requested' && props.can.release;

function release(row: RefundRow): void {
    void confirm.request(`/refunds/${row.id}/release`, { paid_on: paidOn.value }, `Pay the refund on ${row.policy_number}?`, `Pay ${formatMoney(row.amount, currency)}`);
}
function reject(row: RefundRow): void {
    router.post(`/refunds/${row.id}/reject`, { reason: rejectReason.value }, { preserveScroll: true, onSuccess: () => (rejectReason.value = '') });
}
</script>

<template>
    <AppLayout help="refunds" title="Refunds" fill>
        <QueueView
            id="refunds"
            v-model:active="active"
            title="Refunds"
            :columns="columns"
            :rows="refunds"
            :total="refundsTotal ?? null"
            :row-key="(r) => r.id"
            :currency="currency"
            empty-text="No refunds: they follow cancelled policies with money left over."
            :empty-action="{ label: 'Open policies', href: '/policies' }"
            :action="can.request && refundableCount > 0 ? { label: 'Request a refund' } : null"
            :inspector-title="(r) => `Refund on ${r.policy_number}`"
            :inspector-subtitle="(r) => r.reason"
            :primary-label="(r) => (releasable(r) ? 'Pay refund' : undefined)"
            @action="requesting = true"
            @primary="release"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Amount', value: `${formatMoney(row.amount, currency)}`, num: true }, { label: 'Requested', value: formatDate(row.requested_at) }, { label: 'Decision', value: row.decision_reason }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div v-if="releasable(row)" class="mt-4 grid gap-3 border-t border-line pt-4">
                    <Field id="paid_on" label="Paid on" hint="The person who requested the refund cannot pay it."><DateInput v-model="paidOn" /></Field>
                    <Field id="reject_reason" label="Reason to reject" optional><TextInput v-model="rejectReason" /></Field>
                </div>
            </template>
            <template #actions="{ row }">
                <button v-if="releasable(row)" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="rejectReason.trim() === ''" @click="reject(row)">Reject</button>
            </template>
        </QueueView>
        <Drawer v-model:open="requesting" title="Request a refund">
            <FormLayout submit-label="Request refund" :dirty="requestForm.isDirty" :processing="requestForm.processing" :error="(requestForm.errors as Record<string, string>).form" @submit="requestForm.post('/refunds', { onSuccess: () => (requesting = false) })" @cancel="requesting = false">
                <Field id="policy_id" label="Cancelled policy" :error="requestForm.errors.policy_id">
                    <LookupInput id="policy_id" v-model="requestForm.policy_id" type="refundable" @selected="policyPicked" :initial="prefill ? { id: prefill.policy_id, label: prefill.label } : null" placeholder="Policy number or policyholder" />
                </Field>
                <Field id="amount" label="Amount (BDT)" :error="requestForm.errors.amount"><MoneyInput v-model="requestForm.amount" /></Field>
                <Field id="reason" label="Reason" :error="requestForm.errors.reason"><TextInput v-model="requestForm.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
