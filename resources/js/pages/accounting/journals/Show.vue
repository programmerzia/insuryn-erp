<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DocumentList from '@/components/object/DocumentList.vue';
import type { StoredDocumentRow } from '@/components/object/types';
import DetailList from '@/components/table/DetailList.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { stepLabel } from '@/lib/approvals';
import { captionFor, useLineCaptions } from '@/lib/captions';
import { drillFrom } from '@/lib/drill';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import type { PreviewResult } from '@/lib/preview';
import { formatMinor, sumMoney } from '@/lib/money';
import type { JournalDetail, JournalRef } from '@/types/accounting';

interface PendingApproval {
    id: string;
    step: number;
    steps_total: number;
    may_decide: boolean;
}

/**
 * UX brief §6.7 journal viewer: header strip (number, status, total, the action you can take), lines with account codes and dimensions as
 * small labels, the reversal and correction chain, the event and the source document it came from. Reversing always goes through a request
 * that someone else approves.
 */
const props = defineProps<{
    journal: JournalDetail;
    actions?: { approve: boolean; requestReversal: boolean; decideReversal: boolean };
    reversalRequest?: { id: string; status: string; on: string; reason: string; viaApproval: boolean } | null;
    /** Gap fixes W7 (GA-04 remainder): the pending approval of this journal (or of its reversal) and whether the reader holds its current step. */
    approval?: PendingApproval | null;
    reversalApproval?: PendingApproval | null;
    dimensions?: Record<number, { name: string; value: string }[]>;
    sourceLink?: string | null;
    today?: string;
    /** GA-31: a manual journal's supporting documents (null for system postings) and where to attach one (null when the user may not). */
    documents?: StoredDocumentRow[] | null;
    documentUpload?: string | null;
}>();

const actions = computed(() => props.actions ?? { approve: false, requestReversal: false, decideReversal: false });
const reversing = ref(false);
const reversal = ref({ on: props.today ?? '', reason: '' });
const rejectReason = ref('');
const confirm = useJournalConfirm();
const captions = useLineCaptions();
const total = computed(() => formatMinor(sumMoney(props.journal.lines.filter((l) => l.side === 'debit').map((l) => l.amount))));
// GA-31: until it posts, a journal is known by its provisional reference, and its title says where it is ("pending approval"), not "draft".
const title = computed(() => props.journal.number ?? (props.journal.provisionalReference ? `${props.journal.provisionalReference} (${props.journal.status.replaceAll('_', ' ')})` : 'Draft journal'));
const chain = computed<{ label: string; journal: JournalRef }[]>(() =>
    [
        { label: 'Reverses', journal: props.journal.reverses },
        { label: 'Reversed by', journal: props.journal.reversedBy },
        { label: 'Corrects', journal: props.journal.corrects },
        ...props.journal.corrections.map((journal) => ({ label: 'Corrected by', journal })),
    ].filter((link): link is { label: string; journal: JournalRef } => link.journal !== null),
);
const sourceWord = computed(() => (props.journal.source ? props.journal.source.type.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()) : ''));

/** Manual journals and reversals post their own lines (not through accounting events), so the confirmation shows exactly those lines. */
function localPreview(mirrored: boolean, date: string): PreviewResult {
    const lines = props.journal.lines.map((l) => {
        const side = mirrored ? (l.side === 'debit' ? 'credit' : 'debit') : l.side;
        return { account: l.account.code, name: l.account.name, debit: side === 'debit' ? l.amount : null, credit: side === 'credit' ? l.amount : null };
    });
    return { journals: [{ event: mirrored ? 'REVERSAL' : 'MANUAL_JOURNAL', date, lines, totals: { debit: total.value, credit: total.value } }], failures: [], posts: true };
}

function approve(): void {
    Object.assign(confirm.state, { open: true, result: localPreview(false, props.journal.postingDate ?? props.journal.transactionDate), title: `Approve and post ${title.value}?`,
        label: `Post ${total.value} ${props.journal.currency}`, url: `/accounting/journals/${props.journal.id}/approve`, data: {} });
}
function reject(): void {
    router.post(`/accounting/journals/${props.journal.id}/reject`, { reason: rejectReason.value }, { preserveScroll: true });
}
/** Decided through the approval engine (the same route as the approvals inbox): the server previews what this step posts, then the user confirms. */
function approveStep(approval: PendingApproval, what: string): void {
    const last = approval.step >= approval.steps_total;
    void confirm.request(`/approvals/${approval.id}/decide`, { decision: 'approved', return_to: `/accounting/journals/${props.journal.id}` }, `Approve ${what}?`, last ? 'Approve and post' : 'Approve');
}
function rejectStep(approval: PendingApproval): void {
    router.post(`/approvals/${approval.id}/decide`, { decision: 'rejected', reason: rejectReason.value, return_to: `/accounting/journals/${props.journal.id}` }, { preserveScroll: true });
}
function requestReversal(): void {
    router.post(`/accounting/journals/${props.journal.id}/reversal-requests`, reversal.value, { preserveScroll: true, onSuccess: () => (reversing.value = false) });
}
function approveReversal(): void {
    if (!props.reversalRequest) return;
    Object.assign(confirm.state, { open: true, result: localPreview(true, props.reversalRequest.on), title: `Reverse ${title.value}?`, label: `Post the reversal of ${total.value} ${props.journal.currency}`,
        url: `/accounting/reversal-requests/${props.reversalRequest.id}/approve`, data: {} });
}
function rejectReversal(): void {
    if (props.reversalRequest) router.post(`/accounting/reversal-requests/${props.reversalRequest.id}/reject`, { reason: rejectReason.value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout help="accounting" :title="title">
        <div class="grid max-w-[1100px] gap-4">
            <Breadcrumb :base="[{ label: 'Journals', href: '/accounting/journals' }]" />
            <header class="flex flex-wrap items-start gap-x-6 gap-y-2 border-b border-line pb-3">
                <div class="min-w-0 flex-1">
                    <h1 class="text-title font-semibold">{{ title }}</h1>
                    <p class="text-ui text-ink-2">{{ journal.description && /^[A-Z_]+$/.test(journal.description) ? eventLabel(journal.description) : journal.description }}</p>
                </div>
                <dl class="flex gap-6 text-ui">
                    <div><dt class="text-dense text-ink-2">Status</dt><dd><StatusBadge :status="journal.status" /></dd></div>
                    <div><dt class="text-dense text-ink-2">Total ({{ journal.currency }})</dt><dd class="tabular-nums font-medium">{{ total }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Posting date</dt><dd>{{ formatDate(journal.postingDate) }}</dd></div>
                </dl>
                <div class="flex items-center gap-2 self-center">
                    <template v-if="actions.approve">
                        <input v-model="rejectReason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject" />
                        <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="rejectReason.trim() === ''" @click="reject">Reject</button>
                        <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="approve">Approve and post</button>
                    </template>
                    <template v-if="approval?.may_decide">
                        <input v-model="rejectReason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject" />
                        <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="rejectReason.trim() === ''" @click="rejectStep(approval)">Reject</button>
                        <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="approveStep(approval, title)">Review and approve</button>
                    </template>
                    <button v-if="actions.requestReversal" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="reversing = true">Request a reversal</button>
                </div>
            </header>

            <p v-if="approval" class="border-l-2 border-warn pl-3 text-ui" role="status">
                Waiting for approval · {{ stepLabel(approval.step, approval.steps_total).toLowerCase() }}.
                <template v-if="approval.may_decide">{{ approval.step >= approval.steps_total ? 'Yours is the last approval: approving posts these lines.' : 'Approving passes it to the next approver; nothing is posted yet.' }}</template>
                <template v-else>It is decided in the approvals inbox by whoever holds this step.</template>
            </p>
            <p v-if="reversalRequest" class="flex flex-wrap items-center gap-2 border-l-2 border-warn pl-3 text-ui" role="status">
                Reversal requested for {{ formatDate(reversalRequest.on) }}: {{ reversalRequest.reason }} · <StatusBadge :status="reversalRequest.status" />
                <span v-if="reversalRequest.viaApproval && reversalRequest.status === 'pending' && !reversalApproval?.may_decide" class="text-ink-2">Decided in the approvals inbox.</span>
                <template v-if="reversalApproval?.may_decide">
                    <span class="text-ink-2">{{ stepLabel(reversalApproval.step, reversalApproval.steps_total) }}</span>
                    <input v-model="rejectReason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject the reversal" />
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="rejectReason.trim() === ''" @click="rejectStep(reversalApproval)">Reject</button>
                    <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="approveStep(reversalApproval, `the reversal of ${title}`)">Review and approve</button>
                </template>
                <template v-if="actions.decideReversal">
                    <input v-model="rejectReason" class="h-8 w-44 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Reason to reject" aria-label="Reason to reject the reversal" />
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="rejectReason.trim() === ''" @click="rejectReversal">Reject</button>
                    <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="approveReversal">Approve reversal</button>
                </template>
            </p>

            <section aria-labelledby="lines-title">
                <h2 id="lines-title" class="sr-only">Lines</h2>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                        <colgroup><col style="width: 44px" /><col style="width: 280px" /><col /><col style="width: 140px" /><col style="width: 140px" /></colgroup>
                        <thead class="bg-surface-2 text-ink-2">
                            <tr class="h-(--row-h)">
                                <th class="border-b border-line px-3 text-left font-medium">#</th>
                                <th class="border-b border-line px-3 text-left font-medium">Account</th>
                                <th class="border-b border-line px-3 text-left font-medium">Dimensions · memo</th>
                                <th class="border-b border-line px-3 text-right font-medium">Debit ({{ journal.currency }})</th>
                                <th class="border-b border-line px-3 text-right font-medium">Credit ({{ journal.currency }})</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in journal.lines" :key="line.lineNo" class="h-(--row-h)">
                                <td class="border-b border-line px-3 text-ink-2 tabular-nums">{{ line.lineNo }}</td>
                                <td class="border-b border-line px-3 py-1" :class="line.side === 'credit' ? 'pl-7' : ''">
                                    <span class="block truncate"><span class="text-ink-2 tabular-nums">{{ line.account.code }}</span> {{ line.account.name }}</span>
                                    <span v-if="captionFor(captions, line.role, line.side, journal.event?.type)" class="block text-dense text-ink-2">{{ captionFor(captions, line.role, line.side, journal.event?.type) }}</span>
                                </td>
                                <td class="border-b border-line px-3 py-1">
                                    <span class="flex flex-wrap items-center gap-1">
                                        <span v-for="dim in dimensions?.[line.lineNo] ?? []" :key="dim.name" class="rounded-control border border-line px-1.5 text-ink-2"><span class="sr-only">{{ dim.name }}: </span>{{ dim.value }}</span>
                                        <span v-if="line.memo" class="text-ink-2">{{ line.memo }}</span>
                                    </span>
                                </td>
                                <td class="num border-b border-line px-3">{{ line.side === 'debit' ? formatMoney(line.amount) : '' }}</td>
                                <td class="num border-b border-line px-3">{{ line.side === 'credit' ? formatMoney(line.amount) : '' }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-surface-2 font-medium">
                            <tr class="h-(--row-h)"><td /><td class="px-3">Total</td><td /><td class="num px-3">{{ total }}</td><td class="num px-3">{{ total }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 md:grid-cols-2">
                <section aria-labelledby="where-title">
                    <h2 id="where-title" class="mb-2 text-ui font-medium">Where it came from</h2>
                    <DetailList :items="[
                        { label: 'Kind', value: journal.kind },
                        { label: 'Transaction date', value: formatDate(journal.transactionDate) },
                        { label: 'Effective date', value: formatDate(journal.effectiveDate) },
                        { label: 'Event', value: journal.event ? eventLabel(journal.event.type) : 'Entered by hand' },
                        { label: 'Posting rule', value: journal.postingRule ? `${journal.postingRule.code} version ${journal.postingRule.version}` : null },
                        { label: 'Source' },
                        { label: 'Reason', value: journal.reason },
                    ]">
                        <template #Source>
                            <Link v-if="sourceLink" :href="sourceLink" class="inline-flex items-center gap-1 text-accent-text hover:underline" @click="drillFrom(title)">{{ sourceWord }} <ArrowRight :size="14" :stroke-width="1.5" aria-hidden="true" /></Link>
                            <template v-else>{{ journal.source ? sourceWord : '—' }}</template>
                        </template>
                    </DetailList>
                </section>
                <section v-if="documents" aria-labelledby="documents-title" class="md:col-span-2">
                    <h2 id="documents-title" class="mb-2 text-ui font-medium">Supporting documents</h2>
                    <DocumentList :documents="documents" :upload-url="documentUpload" />
                </section>
                <section v-if="chain.length" aria-labelledby="chain-title">
                    <h2 id="chain-title" class="mb-2 text-ui font-medium">Reversals and corrections</h2>
                    <ul class="grid gap-1 text-ui">
                        <li v-for="link in chain" :key="`${link.label}-${link.journal.id}`" class="flex items-center gap-2">
                            <span class="w-28 text-ink-2">{{ link.label }}</span>
                            <Link :href="`/accounting/journals/${link.journal.id}`" class="text-accent-text hover:underline" @click="drillFrom(title)">{{ link.journal.number ?? 'Draft' }}</Link>
                            <StatusBadge :status="link.journal.status" />
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <Drawer v-model:open="reversing" title="Request a reversal">
            <form class="grid gap-4" @submit.prevent="requestReversal">
                <p class="text-ui text-ink-2">A reversal posts mirrored lines on the date you choose. Someone other than you approves it; the original journal is never changed.</p>
                <Field id="reversal-on" label="Reverse on"><DateInput v-model="reversal.on" /></Field>
                <Field id="reversal-reason" label="Why it is reversed"><TextInput v-model="reversal.reason" /></Field>
                <div class="flex justify-end gap-2">
                    <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="reversing = false">Cancel</button>
                    <button type="submit" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Request reversal</button>
                </div>
            </form>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="journal.currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
