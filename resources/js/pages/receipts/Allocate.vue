<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import Kbd from '@/components/ui/Kbd.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney } from '@/lib/money';
import { type PreviewResult, previewJournal } from '@/lib/preview';
import { useShortcut } from '@/lib/shortcuts';
import { toast } from '@/lib/toasts';
import { useUnsavedGuard } from '@/lib/unsaved';

/**
 * UX brief §6.3 allocation workbench: the receipt on the left with a running remaining balance, candidate installments on the right (the
 * payer's own first). ↑↓ and Enter add the active installment for as much as it needs, up to what is left; one commit allocates every line.
 */
interface Candidate { id: string; policy_number: string; no: number; due_date: string; payer: string; outstanding: string; payer_matches: boolean }
const props = defineProps<{
    receipt: { id: string; number: string; amount: string; currency: string; value_date: string; reference: string | null; channel: string; status: string; payer: string | null; open: string };
    suspenseItemId: string | null;
    candidates: Candidate[];
    today: string;
}>();

// Slice 2.1b (D-54): the allocation date starts at the company's today from the server, not the browser's clock.
const form = useForm({
    on: props.today,
    lines: [] as { installment_id: string; amount: string }[],
});
const open = computed(() => parseMoney(props.receipt.open) ?? 0n);
const allocated = computed(() => form.lines.reduce((sum, line) => sum + (parseMoney(line.amount) ?? 0n), 0n));
const remaining = computed(() => open.value - allocated.value);
const byId = computed(() => new Map(props.candidates.map((c) => [c.id, c])));
const available = computed(() => props.candidates.filter((c) => !form.lines.some((l) => l.installment_id === c.id)));
const preview = ref<PreviewResult | null>(null);
const previewOpen = ref(false);
useUnsavedGuard(() => form.lines.length > 0 && !form.processing && !form.wasSuccessful);

const columns: DataColumn<Candidate>[] = [
    { id: 'policy', header: 'Installment', value: (c) => `${c.policy_number} #${c.no}`, width: 170 },
    { id: 'payer', header: 'Payer', value: (c) => (c.payer_matches ? `${c.payer} (this payer)` : c.payer), width: 220 },
    { id: 'due', header: 'Due', type: 'date', value: (c) => c.due_date },
    { id: 'outstanding', header: 'Outstanding', type: 'money', value: (c) => c.outstanding },
];

function add(candidate: Candidate): void {
    if (remaining.value <= 0n) {
        toast('Nothing is left to allocate from this receipt.');
        return;
    }
    const due = parseMoney(candidate.outstanding) ?? 0n;
    const amount = due < remaining.value ? due : remaining.value;
    form.lines.push({ installment_id: candidate.id, amount: formatMinor(amount, { parentheses: false }) });
}

async function review(): Promise<void> {
    if (!props.suspenseItemId || form.lines.length === 0) return;
    if (remaining.value < 0n) return void toast(`The lines exceed the unallocated amount by ${formatMinor(-remaining.value)}.`, { tone: 'danger' });
    const outcome = await previewJournal(`/suspense/${props.suspenseItemId}/allocations`, form.data());
    if (!outcome.ok) return void toast(outcome.errors.form ?? Object.values(outcome.errors)[0] ?? 'The allocation was refused.', { tone: 'danger', duration: 6000 });
    preview.value = outcome.result;
    previewOpen.value = true;
}

function commit(): void {
    form.post(`/suspense/${props.suspenseItemId}/allocations`, { preserveScroll: true, onSuccess: () => form.reset('lines'), onFinish: () => (previewOpen.value = false) });
}

useShortcut('inspector.primary', () => void review(), { allowInInputs: true });
</script>

<template>
    <AppLayout help="receipts" :title="`Allocate ${receipt.number}`" fill>
        <div class="grid min-h-0 flex-1 grid-cols-[minmax(320px,380px)_minmax(0,1fr)]">
            <section class="flex min-h-0 flex-col border-r border-line" aria-label="Receipt">
                <div class="border-b border-line px-4 py-3">
                    <p class="text-dense text-ink-2"><Link :href="`/receipts/${receipt.id}`" class="hover:underline">Receipts › {{ receipt.number }}</Link></p>
                    <h1 class="text-title font-semibold">Allocate {{ receipt.number }}</h1>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <DetailList :items="[{ label: 'Payer', value: receipt.payer }, { label: 'Value date', value: formatDate(receipt.value_date) }, { label: 'Reference', value: receipt.reference }, { label: 'Received', value: `${formatMoney(receipt.amount)} ${receipt.currency}`, num: true }]" />
                    <h2 class="mt-5 mb-2 text-ui font-medium">Lines</h2>
                    <p v-if="form.lines.length === 0" class="text-ui text-ink-2">Pick an installment on the right and press Enter. The amount fills with what it needs, up to what is left.</p>
                    <ul class="grid gap-2">
                        <li v-for="(line, index) in form.lines" :key="line.installment_id" class="grid grid-cols-[minmax(0,1fr)_120px_28px] items-center gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-ui">{{ byId.get(line.installment_id)?.policy_number }} #{{ byId.get(line.installment_id)?.no }}</p>
                                <p class="truncate text-dense text-ink-2">{{ byId.get(line.installment_id)?.payer }} · of {{ byId.get(line.installment_id)?.outstanding }}</p>
                            </div>
                            <MoneyInput :id="`line-${index}`" v-model="line.amount" :aria-label="`Amount for ${byId.get(line.installment_id)?.policy_number} #${byId.get(line.installment_id)?.no}`" />
                            <button type="button" class="inline-flex size-7 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :aria-label="`Remove line ${index + 1}`" @click="form.lines.splice(index, 1)"><X :size="14" :stroke-width="1.5" /></button>
                        </li>
                    </ul>
                </div>
                <div class="grid gap-3 border-t border-line bg-surface-2 px-4 py-3">
                    <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-ui tabular-nums">
                        <dt class="text-ink-2">Unallocated before</dt><dd class="text-right">{{ formatMoney(receipt.open) }}</dd>
                        <dt class="text-ink-2">These lines</dt><dd class="text-right">{{ formatMinor(allocated) }}</dd>
                        <dt class="font-medium">Remaining</dt>
                        <dd class="text-right text-section font-semibold" :class="remaining < 0n ? 'text-danger' : ''" aria-live="polite">{{ formatMinor(remaining) }}</dd>
                    </dl>
                    <label class="grid gap-1 text-ui font-medium" for="allocate-on">Allocation date<DateInput id="allocate-on" v-model="form.on" /></label>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50"
                        :disabled="!suspenseItemId || form.lines.length === 0 || remaining < 0n || form.processing"
                        @click="review"
                    >
                        Allocate {{ formatMinor(allocated) }} <Kbd keys="Ctrl+Enter" class="text-accent-ink" />
                    </button>
                    <p v-if="!suspenseItemId" class="text-dense text-ink-2">This receipt has nothing left in suspense.</p>
                </div>
            </section>
            <section class="flex min-h-0 flex-col" aria-label="Candidate installments">
                <DataTable id="allocation-candidates" :open-on-click="false" compact-toolbar label="Candidate installments" :columns="columns" :rows="available" :row-key="(c) => c.id" currency="BDT" :url-sync="false" empty-text="No installments are waiting for payment." @open="add">
                    <template #toolbar>
                        <h2 class="text-section font-semibold whitespace-nowrap">Waiting for payment</h2>
                        <span class="ml-3 text-dense whitespace-nowrap text-ink-2 max-xl:hidden">↑↓ choose · Enter adds · / filters</span>
                    </template>
                    <template #cell-payer="{ row }">
                        <span :class="row.payer_matches ? 'text-ink' : 'text-ink-2'">{{ row.payer }}</span>
                        <span v-if="row.payer_matches" class="ml-1.5 inline-flex items-center gap-1 text-dense text-ok"><span class="size-1.5 rounded-full bg-ok" aria-hidden="true" />This payer</span>
                    </template>
                </DataTable>
            </section>
        </div>
        <JournalPreviewDialog v-model:open="previewOpen" :result="preview" :title="`Allocate ${formatMinor(allocated)} from ${receipt.number}?`" :confirm-label="`Allocate ${formatMinor(allocated)} ${receipt.currency}`" :currency="receipt.currency" :processing="form.processing" @confirm="commit" />
    </AppLayout>
</template>
