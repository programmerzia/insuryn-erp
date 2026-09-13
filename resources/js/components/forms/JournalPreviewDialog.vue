<script setup lang="ts">
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import Kbd from '@/components/ui/Kbd.vue';
import { captionFor, useLineCaptions } from '@/lib/captions';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';
import type { PreviewResult } from '@/lib/preview';

/**
 * Brief §1.6 / §4: the deliberate stop before money moves — the exact journal lines (account, debit, credit) the action will post, and the
 * button says what happens, and each line says what it means in plain words (session S5). Ctrl+Enter confirms, Esc goes back to the form.
 */
defineProps<{ result: PreviewResult | null; title: string; confirmLabel: string; currency: string; processing?: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();
const captions = useLineCaptions();
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <DialogContent
                class="fixed top-[12vh] left-1/2 z-50 flex max-h-[76vh] w-[640px] max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-col rounded-panel border border-line bg-surface text-ink shadow-float outline-none"
                :aria-describedby="undefined"
                @keydown.ctrl.enter.prevent="emit('confirm')"
                @keydown.meta.enter.prevent="emit('confirm')"
            >
                <div class="border-b border-line px-4 py-3">
                    <DialogTitle class="text-section font-semibold">{{ title }}</DialogTitle>
                    <p v-if="result?.posts" class="text-ui text-ink-2">These entries are posted to the ledger when you confirm.</p>
                    <p v-else class="text-ui text-ink-2">Nothing is posted yet: this goes for approval or has no accounting effect on its own.</p>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <section v-for="(journal, index) in result?.journals ?? []" :key="index" class="mb-4 last:mb-0">
                        <h3 class="mb-1 text-ui font-medium">{{ eventLabel(journal.event) }} <span class="font-normal text-ink-2">· {{ formatDate(journal.date) }}</span></h3>
                        <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                            <colgroup><col /><col style="width: 132px" /><col style="width: 132px" /></colgroup>
                            <thead class="bg-surface-2 text-ink-2">
                                <tr class="h-8">
                                    <th class="border-y border-line px-2 text-left font-medium">Account</th>
                                    <th class="border-y border-line px-2 text-right font-medium">Debit ({{ currency }})</th>
                                    <th class="border-y border-line px-2 text-right font-medium">Credit ({{ currency }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, i) in journal.lines" :key="i" class="h-8 align-top">
                                    <td class="border-b border-line px-2 py-1.5" :class="line.credit ? 'pl-6' : ''">
                                        <span class="block truncate"><span class="text-ink-2 tabular-nums">{{ line.account }}</span> {{ line.name }}</span>
                                        <span v-if="captionFor(captions, line.role, line.debit ? 'debit' : 'credit')" class="block text-ink-2">{{ captionFor(captions, line.role, line.debit ? 'debit' : 'credit') }}</span>
                                    </td>
                                    <td class="num border-b border-line px-2 py-1.5">{{ formatMoney(line.debit) }}</td>
                                    <td class="num border-b border-line px-2 py-1.5">{{ formatMoney(line.credit) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="h-8 font-medium">
                                    <td class="px-2">Total</td>
                                    <td class="num px-2">{{ formatMoney(journal.totals.debit) }}</td>
                                    <td class="num px-2">{{ formatMoney(journal.totals.credit) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </section>
                    <p v-for="failure in result?.failures ?? []" :key="failure.event" class="mt-2 text-ui text-danger" role="alert">
                        {{ eventLabel(failure.event) }} would not post: {{ failure.reason }}
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-line px-4 py-3">
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="open = false">Back to the form <Kbd keys="Esc" /></button>
                    <button
                        type="button"
                        :disabled="processing || (result?.failures.length ?? 0) > 0"
                        class="inline-flex h-8 items-center gap-1.5 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }} <Kbd keys="Ctrl+Enter" class="text-accent-ink" />
                    </button>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
