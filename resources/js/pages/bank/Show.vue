<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Upload, Wand2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import Drawer from '@/components/ui/Drawer.vue';
import { useMoneyForm } from '@/lib/moneyForm';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import Kbd from '@/components/ui/Kbd.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, parseMoney } from '@/lib/money';
import { formatDate, formatMoney } from '@/lib/format';
import { useShortcut } from '@/lib/shortcuts';
import { usePermissions } from '@/lib/permissions';

/**
 * UX brief §6.4 bank matching: statement lines and ledger lines side by side; the best suggestion for each statement line with its
 * confidence and why; Enter accepts it; several ledger lines selected with Space match one statement line (merge); a line with no ledger
 * entry is explained. Matching posts nothing, so it is instant. Splitting one ledger line across statement lines is not supported yet.
 */
interface StatementLine { id: string; posted_on: string; amount: string; reference: string | null; description: string | null }
interface LedgerLine { journal_line_id: string; journal_id: string; journal_number: string | null; posting_date: string; amount: string; reference: string | null; receipt_number: string | null }
interface Suggestion { statement_line_id: string; journal_line_id: string; confidence: number; why: string }
const props = defineProps<{
    account: { id: string; bank_name: string; account_no_masked: string; currency: string; gl_account_id?: string }; asOf: string; unmatched: { statement_lines: StatementLine[]; journal_lines: LedgerLine[] }; suggestions?: Suggestion[];
    branches?: { id: string; code: string; name: string }[];
}>();

const { can } = usePermissions();
const activeStatement = ref<string | null>(null);
const ledgerTable = ref<{ state: { selectedRows: { value: unknown[] }; clearSelection: () => void } } | null>(null);
const explanation = ref('');
const upload = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
const ledgerById = computed(() => new Map(props.unmatched.journal_lines.map((l) => [l.journal_line_id, l])));
const bestFor = (lineId: string) => (props.suggestions ?? []).find((s) => s.statement_line_id === lineId && ledgerById.value.has(s.journal_line_id)) ?? null;
const statement = computed(() => props.unmatched.statement_lines.find((l) => l.id === activeStatement.value) ?? null);
const confidenceWord = (value: number) => (value >= 100 ? 'Strong match' : 'Possible match');

/** Ledger lines for the active statement line: its suggestions first. */
const ledgerRows = computed(() => {
    if (!statement.value) return props.unmatched.journal_lines;
    const suggested = new Map((props.suggestions ?? []).filter((s) => s.statement_line_id === statement.value!.id).map((s) => [s.journal_line_id, s.confidence]));
    return [...props.unmatched.journal_lines].sort((a, b) => (suggested.get(b.journal_line_id) ?? 0) - (suggested.get(a.journal_line_id) ?? 0));
});
const suggestionFor = (ledgerId: string) => (statement.value ? (props.suggestions ?? []).find((s) => s.statement_line_id === statement.value!.id && s.journal_line_id === ledgerId) : undefined);

const statementColumns: DataColumn<StatementLine>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (l) => l.posted_on },
    { id: 'details', header: 'Details', value: (l) => [l.reference, l.description].filter(Boolean).join(' · '), width: 200, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (l) => l.amount, total: true },
    { id: 'suggestion', header: 'Suggested match', value: (l) => bestFor(l.id)?.why ?? null, width: 220 },
];
const ledgerColumns: DataColumn<LedgerLine>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (l) => l.posting_date },
    { id: 'journal', header: 'Journal', value: (l) => l.journal_number, href: (l) => `/accounting/journals/${l.journal_id}`, width: 150 },
    { id: 'reference', header: 'Receipt · reference', value: (l) => [l.receipt_number, l.reference].filter(Boolean).join(' · '), width: 200, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (l) => l.amount, total: true },
];

function match(statementLineId: string, journalLineIds: string[]): void {
    router.post(`/bank/lines/${statementLineId}/match`, { journal_line_ids: journalLineIds }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            activeStatement.value = null;
            ledgerTable.value?.state.clearSelection();
        },
    });
}

function acceptSuggestion(line: StatementLine): void {
    activeStatement.value = line.id;
    const best = bestFor(line.id);
    if (!best || !can('bank.match')) return;
    match(line.id, [best.journal_line_id]);
}

const selectedLedger = computed(() => (ledgerTable.value?.state.selectedRows.value ?? []) as LedgerLine[]);
const selectedTotal = computed(() => selectedLedger.value.reduce((sum, l) => sum + (parseMoney(l.amount) ?? 0n), 0n));
const mergeReady = computed(() => statement.value !== null && selectedLedger.value.length > 0 && selectedTotal.value === parseMoney(statement.value.amount));

function mergeSelected(): void {
    if (!statement.value || !mergeReady.value) return;
    match(statement.value.id, selectedLedger.value.map((l) => l.journal_line_id));
}

useShortcut('inspector.primary', () => mergeSelected(), { allowInInputs: true });

function explain(): void {
    if (!statement.value || explanation.value.trim() === '') return;
    router.post(`/bank/lines/${statement.value.id}/explain`, { reason: explanation.value }, { preserveScroll: true, onSuccess: () => { explanation.value = ''; activeStatement.value = null; } });
}

/** Gap fix GA-27: ledger lines that cancel each other out (a bounced cheque and its reversal) are offset against each other, with no statement line. */
const offsetReady = computed(() => statement.value === null && selectedLedger.value.length >= 2 && selectedTotal.value === 0n);
function offsetSelected(): void {
    if (!offsetReady.value) return;
    router.post(`/bank/${props.account.id}/offset`, { journal_line_ids: selectedLedger.value.map((l) => l.journal_line_id) }, { preserveScroll: true, preserveState: true, onSuccess: () => ledgerTable.value?.state.clearSelection() });
}

/** Gap fix GA-27: an unknown credit becomes a receipt held in suspense, in one step (journal preview first). */
const receiving = ref(false);
const receipt = useMoneyForm(() => `/bank/lines/${statement.value?.id ?? ''}/receipt`, { branch_id: props.branches?.[0]?.id ?? '' }, () => { receiving.value = false; activeStatement.value = null; });
const isCredit = (line: StatementLine) => (parseMoney(line.amount) ?? 0n) > 0n;
/** Gap fix GA-27: a charge the bank deducted opens a manual journal prefilled with the bank charge, for approval as usual. */
function bankChargeHref(line: StatementLine): string {
    const amount = parseMoney(line.amount) ?? 0n;
    const params = new URLSearchParams({
        'prefill[date]': line.posted_on, 'prefill[amount]': formatMinor(amount < 0n ? -amount : amount).replaceAll(',', ''), 'prefill[debit_role]': 'bank_charges',
        'prefill[credit_account]': props.account.gl_account_id ?? '', 'prefill[memo]': [line.reference, line.description].filter(Boolean).join(' '),
        'prefill[description]': `Bank charges, ${props.account.bank_name} ${props.account.account_no_masked}`, 'prefill[reason]': 'Charge on the bank statement',
    });
    return `/accounting/journals/create?${params.toString()}`;
}

function importFile(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (upload.file) upload.post(`/bank/${props.account.id}/statements`, { forceFormData: true, preserveScroll: true, onFinish: () => upload.reset() });
}
</script>

<template>
    <AppLayout help="bank" :title="`${account.bank_name} ${account.account_no_masked}`" fill>
        <div class="flex h-11 items-center gap-2 border-b border-line px-4">
            <p class="text-ui text-ink-2"><Link href="/bank" class="hover:underline">Bank</Link> ›</p>
            <h1 class="text-section font-semibold">{{ account.bank_name }} {{ account.account_no_masked }}</h1>
            <DateRangeFilter :url="`/bank/${account.id}`" :as-of="asOf" />
            <div class="ml-auto flex items-center gap-2">
                <template v-if="can('bank.import')">
                    <input ref="fileInput" type="file" accept=".csv,text/csv" class="sr-only" aria-label="Statement CSV file" @change="importFile" />
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" :disabled="upload.processing" @click="fileInput?.click()">
                        <Upload :size="16" :stroke-width="1.5" />{{ upload.processing ? 'Importing…' : 'Import statement' }}
                    </button>
                </template>
                <button v-if="can('bank.match')" type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="router.post(`/bank/${account.id}/auto-match`, {}, { preserveScroll: true })">
                    <Wand2 :size="16" :stroke-width="1.5" />Accept strong matches
                </button>
            </div>
        </div>
        <div class="grid min-h-0 flex-1 grid-cols-2">
            <section class="flex min-h-0 flex-col border-r border-line" aria-label="Statement lines">
                <DataTable id="bank-statement-lines" :open-on-click="false" compact-toolbar v-model:active="activeStatement" label="Statement lines" :columns="statementColumns" :rows="unmatched.statement_lines" :row-key="(l) => l.id" :currency="account.currency" :url-sync="false" empty-text="Every statement line is matched or explained." @open="acceptSuggestion">
                    <template #toolbar><h2 class="text-ui font-semibold">Statement lines</h2><span class="ml-2 text-dense text-ink-2 max-2xl:hidden">Enter accepts the suggestion</span></template>
                    <template #cell-suggestion="{ row }">
                        <span v-if="bestFor(row.id)" class="inline-flex items-center gap-1.5" :title="bestFor(row.id)!.why">
                            <span class="size-1.5 rounded-full" :class="bestFor(row.id)!.confidence >= 100 ? 'bg-ok' : 'bg-warn'" aria-hidden="true" />
                            {{ confidenceWord(bestFor(row.id)!.confidence) }} · {{ ledgerById.get(bestFor(row.id)!.journal_line_id)?.journal_number }}
                        </span>
                        <span v-else class="text-ink-2">No suggestion</span>
                    </template>
                </DataTable>
                <div v-if="statement" class="flex flex-wrap items-center gap-2 border-t border-line bg-surface-2 px-4 py-2">
                    <input v-model="explanation" class="h-8 min-w-0 flex-1 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="No ledger entry? Say why" aria-label="Explanation" @keydown.enter.prevent="explain" />
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="explanation.trim() === ''" @click="explain">Explain</button>
                    <!-- Gap fix GA-27: book what the ledger is missing instead of only explaining it. -->
                    <button v-if="isCredit(statement) && can('receipt.create') && !bestFor(statement.id)" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="receiving = true">Record receipt</button>
                    <Link v-if="!isCredit(statement) && can('accounting.create_manual_journal') && !bestFor(statement.id)" :href="bankChargeHref(statement)" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Post as bank charge</Link>
                </div>
            </section>
            <section class="flex min-h-0 flex-col" aria-label="Ledger lines">
                <DataTable ref="ledgerTable" id="bank-ledger-lines" compact-toolbar label="Ledger lines" :columns="ledgerColumns" :rows="ledgerRows" :row-key="(l) => l.journal_line_id" :currency="account.currency" selectable :url-sync="false" empty-text="No unmatched ledger lines.">
                    <template #toolbar><h2 class="text-ui font-semibold">Ledger lines</h2><span class="ml-2 text-dense text-ink-2 max-2xl:hidden">{{ statement ? 'Suggestions for the chosen statement line first' : 'Choose a statement line' }}</span></template>
                    <template #cell-date="{ row }">
                        <span class="inline-flex items-center gap-1.5">
                            <span v-if="suggestionFor(row.journal_line_id)" class="size-1.5 rounded-full" :class="suggestionFor(row.journal_line_id)!.confidence >= 100 ? 'bg-ok' : 'bg-warn'" :title="suggestionFor(row.journal_line_id)!.why" aria-hidden="true" />
                            {{ formatDate(row.posting_date) }}
                        </span>
                    </template>
                    <template #bulk>
                        <template v-if="!statement && can('bank.match')">
                            <span class="text-ui text-ink-2" :class="{ 'text-danger': selectedLedger.length >= 2 && !offsetReady }">
                                {{ offsetReady ? 'These cancel each other out.' : `Choose the statement line these pay, or lines that net to zero (now ${formatMinor(selectedTotal)}).` }}
                            </span>
                            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="!offsetReady" @click="offsetSelected">Offset selected</button>
                        </template>
                        <span v-if="statement || !can('bank.match')" class="text-ui text-ink-2" :class="{ 'text-danger': statement && !mergeReady }">
                            <template v-if="!statement">Choose the statement line these pay.</template>
                            <template v-else-if="!mergeReady">Selected {{ formatMinor(selectedTotal) }} does not equal the statement line {{ formatMoney(statement.amount) }}.</template>
                            <template v-else>Equals the statement line.</template>
                        </span>
                        <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" :disabled="!mergeReady" @click="mergeSelected">
                            Match selected <Kbd keys="Ctrl+Enter" class="text-accent-ink" />
                        </button>
                    </template>
                </DataTable>
            </section>
        </div>
        <Drawer v-model:open="receiving" title="Record receipt from the statement">
            <FormLayout v-if="statement" submit-label="Review the receipt" :dirty="true" :processing="receipt.form.processing" :error="(receipt.form.errors as Record<string, string>).form" @submit="receipt.review" @cancel="receiving = false">
                <p class="text-ui text-ink-2">{{ formatMoney(statement.amount) }} {{ account.currency }} paid into {{ account.bank_name }} on {{ formatDate(statement.posted_on) }} ({{ [statement.reference, statement.description].filter(Boolean).join(' · ') || 'no reference' }}). It is held in suspense until someone matches it to a policy, and the statement line is matched to it.</p>
                <Field id="receipt_branch" label="Branch" :error="receipt.form.errors.branch_id">
                    <SelectInput id="receipt_branch" v-model="receipt.form.branch_id" :options="(branches ?? []).map((b) => ({ value: b.id, label: `${b.code} ${b.name}` }))" />
                </Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="receipt.previewOpen.value" :result="receipt.preview.value" title="Record this receipt?" confirm-label="Record the receipt" :currency="account.currency" :processing="receipt.form.processing" @confirm="receipt.post" />
    </AppLayout>
</template>
