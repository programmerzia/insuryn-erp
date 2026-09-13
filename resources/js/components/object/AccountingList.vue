<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { AccountingJournal } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import { drillFrom } from '@/lib/drill';
import { captionFor, useLineCaptions } from '@/lib/captions';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';

/** Brief §1.5: the journals behind a business record, one click away, each linking to the journal viewer. Each line says what it means (session S5). */
defineProps<{ journals: AccountingJournal[]; currency: string; from: string }>();
const captions = useLineCaptions();
</script>

<template>
    <div v-if="journals.length" class="grid gap-4">
        <section v-for="journal in journals" :key="journal.id" class="border border-line">
            <header class="flex h-9 items-center gap-3 border-b border-line bg-surface-2 px-3 text-ui">
                <Link :href="`/accounting/journals/${journal.id}`" class="font-medium text-accent-text hover:underline" @click="drillFrom(from)">{{ journal.number ?? 'Draft' }}</Link>
                <span>{{ journal.event ? eventLabel(journal.event) : '' }}</span>
                <span class="text-ink-2">{{ formatDate(journal.date) }}</span>
                <StatusBadge :status="journal.status" class="ml-auto" />
            </header>
            <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                <colgroup><col /><col style="width: 128px" /><col style="width: 128px" /></colgroup>
                <thead class="sr-only"><tr><th>Account</th><th>Debit ({{ currency }})</th><th>Credit ({{ currency }})</th></tr></thead>
                <tbody>
                    <tr v-for="(line, index) in journal.lines" :key="index" class="h-7 align-top">
                        <td class="px-3 py-1" :class="line.credit ? 'pl-8' : ''">
                            <span class="block truncate"><span class="text-ink-2 tabular-nums">{{ line.account }}</span> {{ line.name }}</span>
                            <span v-if="captionFor(captions, line.role, line.debit ? 'debit' : 'credit')" class="block text-ink-2" data-caption>{{ captionFor(captions, line.role, line.debit ? 'debit' : 'credit') }}</span>
                        </td>
                        <td class="num px-3 py-1">{{ formatMoney(line.debit) }}</td>
                        <td class="num px-3 py-1">{{ formatMoney(line.credit) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
    <p v-else class="text-ui text-ink-2">Nothing has been posted for this record.</p>
</template>
