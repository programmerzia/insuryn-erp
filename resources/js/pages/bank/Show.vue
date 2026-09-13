<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Upload, Wand2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
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
const props = defineProps<{ account: { id: string; bank_name: string; account_no_masked: string; currency: string }; asOf: string; unmatched: { statement_lines: StatementLine[]; journal_lines: LedgerLine[] }; suggestions?: Suggestion[] }>();

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
                <div v-if="statement" class="flex items-center gap-2 border-t border-line bg-surface-2 px-4 py-2">
                    <input v-model="explanation" class="h-8 min-w-0 flex-1 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="No ledger entry? Say why (e.g. bank charges)" aria-label="Explanation" @keydown.enter.prevent="explain" />
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="explanation.trim() === ''" @click="explain">Explain</button>
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
                        <span class="text-ui text-ink-2" :class="{ 'text-danger': statement && !mergeReady }">
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
    </AppLayout>
</template>
