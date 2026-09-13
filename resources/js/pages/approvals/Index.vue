<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

interface ApprovalRow { id: string; object_type: string; title: string; step: number; requested_by: string; requested_at: string; link: string | null; amount: string | null }
const props = defineProps<{ approvals: ApprovalRow[] }>();

const active = ref<string | null>(null);
const reason = ref('');
const confirm = useJournalConfirm();
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const columns: DataColumn<ApprovalRow>[] = [
    { id: 'title', header: 'Waiting for approval', value: (a) => a.title, href: (a) => a.link, width: 280 },
    { id: 'type', header: 'Kind', value: (a) => words(a.object_type), width: 170 },
    { id: 'amount', header: 'Amount', type: 'money', value: (a) => a.amount, total: true },
    { id: 'requested_by', header: 'Requested by', value: (a) => a.requested_by, width: 160 },
    { id: 'requested_at', header: 'Requested', type: 'date', value: (a) => a.requested_at },
    { id: 'step', header: 'Step', type: 'number', value: (a) => a.step, width: 72 },
];

function approve(row: ApprovalRow): void {
    void confirm.request(`/approvals/${row.id}/decide`, { decision: 'approved' }, `Approve ${row.title}?`, 'Approve');
}
function reject(row: ApprovalRow): void {
    router.post(`/approvals/${row.id}/decide`, { decision: 'rejected', reason: reason.value }, { preserveScroll: true, onSuccess: () => { reason.value = ''; active.value = null; } });
}
void props;
</script>

<template>
    <AppLayout title="Approvals" fill>
        <QueueView
            id="approvals"
            v-model:active="active"
            title="Approvals"
            :columns="columns"
            :rows="approvals"
            :row-key="(a) => a.id"
            currency="BDT"
            empty-text="Nothing is waiting for your decision."
            :empty-action="{ label: 'Back to Home', href: '/home' }"
            :inspector-title="(a) => a.title"
            :inspector-subtitle="(a) => `${words(a.object_type)} · step ${a.step}`"
            :primary-label="() => 'Approve'"
            @primary="approve"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Amount', value: row.amount ? `${formatMoney(row.amount)} BDT` : null, num: true }, { label: 'Requested by', value: row.requested_by }, { label: 'Requested', value: row.requested_at }, { label: 'Step', value: row.step }]" />
                <p class="mt-4 text-ui text-ink-2">You never see your own requests here. Approving may post to the ledger; you see the entries first.</p>
            </template>
            <template #actions="{ row }">
                <input v-model="reason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject" />
                <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui text-ink hover:bg-surface-2" :disabled="reason.trim() === ''" @click="reject(row)">Reject</button>
            </template>
        </QueueView>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
