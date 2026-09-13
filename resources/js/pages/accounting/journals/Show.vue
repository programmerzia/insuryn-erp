<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StatusBadge from '@/components/StatusBadge.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { JournalDetail, JournalRef } from '@/types/accounting';

const props = defineProps<{
    journal: JournalDetail;
    actions?: { approve: boolean; requestReversal: boolean; decideReversal: boolean };
    reversalRequest?: { id: string; status: string; on: string; reason: string; viaApproval: boolean } | null;
}>();

const actions = computed(() => props.actions ?? { approve: false, requestReversal: false, decideReversal: false });
const reversing = ref(false);
const approveForm = useForm({});
const rejectForm = useForm({ reason: '' });
const reversalForm = useForm({ on: '', reason: '' });
const decisionForm = useForm({ reason: '' });

const links = computed<{ label: string; journal: JournalRef }[]>(() =>
    [
        { label: 'Reverses', journal: props.journal.reverses },
        { label: 'Reversed by', journal: props.journal.reversedBy },
        { label: 'Corrects', journal: props.journal.corrects },
        ...props.journal.corrections.map((journal) => ({ label: 'Corrected by', journal })),
    ].filter((link): link is { label: string; journal: JournalRef } => link.journal !== null),
);

const facts = computed(() => [
    { term: 'Kind', value: props.journal.kind },
    { term: 'Transaction date', value: props.journal.transactionDate },
    { term: 'Posting date', value: props.journal.postingDate },
    { term: 'Effective date', value: props.journal.effectiveDate },
    { term: 'Posting rule', value: props.journal.postingRule ? `${props.journal.postingRule.code} v${props.journal.postingRule.version}` : '—' },
    { term: 'Source event', value: props.journal.event ? props.journal.event.type : '—' },
]);
</script>

<template>
    <AppLayout :title="journal.number ?? 'Draft journal'">
        <Link href="/accounting/journals" class="text-sm text-blueprint hover:underline">← All journals</Link>

        <div class="mt-3 mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-mono text-3xl font-bold">{{ journal.number ?? 'Unnumbered draft' }}</h1>
                <p v-if="journal.description" class="mt-1 text-ivory-dim">{{ journal.description }}</p>
            </div>
            <StatusBadge :status="journal.status" />
        </div>

        <FormBanner />
        <div v-if="actions.approve || actions.requestReversal" class="mb-6 flex flex-wrap items-center gap-2">
            <template v-if="actions.approve">
                <Button :disabled="approveForm.processing" @click="approveForm.post(`/accounting/journals/${props.journal.id}/approve`)">Approve and post</Button>
                <Input v-model="rejectForm.reason" placeholder="Reason to reject" class="w-56" aria-label="Reason to reject" />
                <Button variant="ghost" @click="rejectForm.post(`/accounting/journals/${props.journal.id}/reject`)">Reject</Button>
            </template>
            <Button v-if="actions.requestReversal && !reversing" variant="ghost" @click="reversing = true">Request reversal</Button>
            <form v-if="reversing" class="flex flex-wrap items-center gap-2" @submit.prevent="reversalForm.post(`/accounting/journals/${props.journal.id}/reversal-requests`, { onSuccess: () => (reversing = false) })">
                <Input v-model="reversalForm.on" type="date" aria-label="Reversal date" />
                <Input v-model="reversalForm.reason" placeholder="Why it is reversed" class="w-64" aria-label="Reversal reason" />
                <Button type="submit" :disabled="reversalForm.processing">Request</Button>
            </form>
        </div>
        <p v-if="reversalRequest" class="mb-6 flex flex-wrap items-center gap-2 rounded-md border border-line bg-surface px-4 py-3 text-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-blueprint">Reversal request</span>
            <StatusBadge :status="reversalRequest.status" /> on {{ reversalRequest.on }} — {{ reversalRequest.reason }}
            <span v-if="reversalRequest.viaApproval && reversalRequest.status === 'pending'" class="text-ivory-dim">(decided in the approvals inbox)</span>
            <template v-if="actions.decideReversal">
                <Button @click="decisionForm.post(`/accounting/reversal-requests/${reversalRequest.id}/approve`)">Approve reversal</Button>
                <Input v-model="decisionForm.reason" placeholder="Reason to reject" class="w-48" aria-label="Reason to reject reversal" />
                <Button variant="ghost" @click="decisionForm.post(`/accounting/reversal-requests/${reversalRequest.id}/reject`)">Reject</Button>
            </template>
        </p>

        <dl class="mb-6 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-3">
            <div v-for="fact in facts" :key="fact.term" class="bg-surface px-4 py-3">
                <dt class="text-xs font-semibold uppercase tracking-wider text-blueprint">{{ fact.term }}</dt>
                <dd class="mt-1 font-mono text-sm">{{ fact.value }}</dd>
            </div>
        </dl>

        <p v-if="journal.reason" class="mb-6 rounded-md border border-line bg-surface px-4 py-3 text-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-blueprint">Reason</span>
            <span class="ml-2">{{ journal.reason }}</span>
        </p>

        <section v-if="links.length > 0" class="mb-6" aria-labelledby="chain">
            <h2 id="chain" class="mb-2 text-lg font-bold">Correction chain</h2>
            <ul class="flex flex-wrap gap-3">
                <li v-for="link in links" :key="`${link.label}-${link.journal.id}`" class="rounded-md border border-line bg-surface px-3 py-2 text-sm">
                    <span class="text-ivory-dim">{{ link.label }}</span>
                    <Link :href="`/accounting/journals/${link.journal.id}`" class="ml-2 font-mono text-blueprint hover:underline">{{ link.journal.number ?? 'draft' }}</Link>
                    <span class="ml-2"><StatusBadge :status="link.journal.status" /></span>
                </li>
            </ul>
        </section>

        <h2 class="mb-2 text-lg font-bold">Lines</h2>
        <Table>
            <TableHeader>
                <TableRow class="hover:bg-transparent">
                    <TableHead class="w-12">#</TableHead>
                    <TableHead>Account</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Memo</TableHead>
                    <TableHead class="text-right">Debit</TableHead>
                    <TableHead class="text-right">Credit</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="line in journal.lines" :key="line.lineNo">
                    <TableCell class="font-mono text-ivory-dim">{{ line.lineNo }}</TableCell>
                    <TableCell><span class="font-mono text-ivory-dim">{{ line.account.code }}</span> {{ line.account.name }}</TableCell>
                    <TableCell class="font-mono text-xs text-ivory-dim">{{ line.role ?? '—' }}</TableCell>
                    <TableCell class="text-ivory-dim">{{ line.memo ?? '' }}</TableCell>
                    <TableCell class="text-right font-mono tabular-nums">{{ line.side === 'debit' ? line.amount : '' }}</TableCell>
                    <TableCell class="text-right font-mono tabular-nums">{{ line.side === 'credit' ? line.amount : '' }}</TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </AppLayout>
</template>
