<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { refundAmount } from '@/lib/drawerDefaults';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

interface RefundRow { id: string; policy_number: string | null; amount: string; reason: string; status: string; requested_at: string; decision_reason: string | null }
const props = defineProps<{
    refundable: { policy_id: string; policy_number: string | null; policyholder: string; available: string }[];
    refunds: RefundRow[];
    can: { request: boolean; release: boolean };
    /** GA-01: opened from a cancellation, the request starts with that policy and what is still refundable on it. */
    prefill?: { policy_id: string; amount: string; reason: string } | null;
}>();

const active = ref<string | null>(null);
const requesting = ref(Boolean(props.prefill && props.can.request));
const requestForm = useForm({ policy_id: props.prefill?.policy_id ?? '', amount: props.prefill?.amount ?? '', reason: props.prefill?.reason ?? '' });
// Gap fix GA-19: a refund is paid today unless changed; the request starts with what is still refundable on the chosen policy (GA-01's prefill kept).
const today = useBusinessToday();
const paidOn = ref(today);
watch(() => requestForm.policy_id, (policyId, previous) => {
    if (requestForm.amount === '' || requestForm.amount === refundAmount(props.refundable, previous ?? '')) requestForm.amount = refundAmount(props.refundable, policyId);
});
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
    void confirm.request(`/refunds/${row.id}/release`, { paid_on: paidOn.value }, `Pay the refund on ${row.policy_number}?`, `Pay ${formatMoney(row.amount)} BDT`);
}
function reject(row: RefundRow): void {
    router.post(`/refunds/${row.id}/reject`, { reason: rejectReason.value }, { preserveScroll: true, onSuccess: () => (rejectReason.value = '') });
}
</script>

<template>
    <AppLayout title="Refunds" fill>
        <QueueView
            id="refunds"
            v-model:active="active"
            title="Refunds"
            :columns="columns"
            :rows="refunds"
            :row-key="(r) => r.id"
            currency="BDT"
            empty-text="No refunds: they follow cancelled policies with money left over."
            :empty-action="{ label: 'Open policies', href: '/policies' }"
            :action="can.request && refundable.length ? { label: 'Request a refund' } : null"
            :inspector-title="(r) => `Refund on ${r.policy_number}`"
            :inspector-subtitle="(r) => r.reason"
            :primary-label="(r) => (releasable(r) ? 'Pay refund' : undefined)"
            @action="requesting = true"
            @primary="release"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Amount', value: `${formatMoney(row.amount)} BDT`, num: true }, { label: 'Requested', value: formatDate(row.requested_at) }, { label: 'Decision', value: row.decision_reason }]">
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
                    <SelectInput id="policy_id" v-model="requestForm.policy_id" placeholder="Choose a policy" :options="refundable.map((r) => ({ value: r.policy_id, label: `${r.policy_number} · ${r.policyholder} · ${formatMoney(r.available)} due` }))" />
                </Field>
                <Field id="amount" label="Amount (BDT)" :error="requestForm.errors.amount"><MoneyInput v-model="requestForm.amount" /></Field>
                <Field id="reason" label="Reason" :error="requestForm.errors.reason"><TextInput v-model="requestForm.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
