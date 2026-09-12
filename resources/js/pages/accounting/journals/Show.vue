<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { JournalDetail, JournalRef } from '@/types/accounting';

const props = defineProps<{ journal: JournalDetail }>();

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
