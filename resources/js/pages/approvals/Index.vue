<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { approvalOutcome, type ApprovalPreview, stepLabel } from '@/lib/approvals';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
const currency = useEntityCurrency();

interface ApprovalRow {
    id: string;
    object_type: string;
    title: string;
    step: number;
    steps_total?: number;
    final_step?: boolean;
    requested_by: string;
    requested_at: string;
    /** Gap fix GA-04: the request time on the company clock, "14 Sep 2026, 13:43". */
    requested_at_label?: string;
    link: string | null;
    amount: string | null;
    preview?: ApprovalPreview | null;
}
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
    { id: 'requested_at', header: 'Requested', value: (a) => a.requested_at_label ?? a.requested_at, width: 150 },
    { id: 'step', header: 'Step', value: (a) => stepLabel(a.step, a.steps_total), width: 90 },
];

function approve(row: ApprovalRow): void {
    // The server previews what approving does: the journal lines when this decision posts, "nothing is posted yet" when it passes to the next step.
    void confirm.request(`/approvals/${row.id}/decide`, { decision: 'approved' }, `Approve ${row.title}?`, row.final_step && row.preview?.posts_on_final_step ? 'Approve and post' : 'Approve');
}
function reject(row: ApprovalRow): void {
    router.post(`/approvals/${row.id}/decide`, { decision: 'rejected', reason: reason.value }, { preserveScroll: true, onSuccess: () => { reason.value = ''; active.value = null; } });
}
void props;
</script>

<template>
    <AppLayout help="limits" title="Approvals" fill>
        <QueueView
            id="approvals"
            v-model:active="active"
            title="Approvals"
            :columns="columns"
            :rows="approvals"
            :row-key="(a) => a.id"
            :currency="currency"
            empty-text="Nothing is waiting for your decision."
            :empty-action="{ label: 'Back to Home', href: '/home' }"
            :inspector-title="(a) => a.title"
            :inspector-subtitle="(a) => `${words(a.object_type)} · ${stepLabel(a.step, a.steps_total).toLowerCase()}`"
            :primary-label="(a) => (a.final_step && a.preview?.posts_on_final_step ? 'Review and approve' : 'Approve')"
            @primary="approve"
        >
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Amount', value: row.amount ? `${formatMoney(row.amount, currency)}` : null, num: true },
                        { label: 'Requested by', value: row.requested_by },
                        { label: 'Requested', value: row.requested_at_label ?? formatDateTime(row.requested_at) },
                        { label: 'Step', value: stepLabel(row.step, row.steps_total) },
                        ...(row.preview?.details ?? []).map((d) => ({ label: d.label, value: d.date ? formatDate(d.value) : d.value })),
                    ]"
                />
                <Link v-if="row.link" :href="row.link" class="mt-3 inline-block text-ui text-accent-text hover:underline">{{ row.preview?.link_label ?? 'Open it' }}</Link>
                <section v-if="row.preview?.lines.length" class="mt-4" aria-label="Journal lines">
                    <h3 class="mb-1 text-ui font-medium">{{ row.final_step ? 'Approving posts these entries' : 'Entries posted at the last approval' }}</h3>
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                        <colgroup><col /><col style="width: 104px" /><col style="width: 104px" /></colgroup>
                        <thead class="bg-surface-2 text-ink-2">
                            <tr class="h-7">
                                <th class="border-y border-line px-2 text-left font-medium">Account</th>
                                <th class="border-y border-line px-2 text-right font-medium">Debit</th>
                                <th class="border-y border-line px-2 text-right font-medium">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, i) in row.preview.lines" :key="i" class="h-7">
                                <td class="truncate border-b border-line px-2" :class="line.credit ? 'pl-5' : ''" :title="`${line.account} ${line.name}`"><span class="text-ink-2 tabular-nums">{{ line.account }}</span> {{ line.name }}</td>
                                <td class="num border-b border-line px-2">{{ formatMoney(line.debit) }}</td>
                                <td class="num border-b border-line px-2">{{ formatMoney(line.credit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <p class="mt-4 text-ui text-ink-2">{{ approvalOutcome(row.final_step ?? false, row.preview?.posts_on_final_step ?? false) }} You never see your own requests here.</p>
            </template>
            <template #actions="{ row }">
                <input v-model="reason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject" />
                <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui text-ink hover:bg-surface-2" :disabled="reason.trim() === ''" @click="reject(row)">Reject</button>
            </template>
        </QueueView>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
