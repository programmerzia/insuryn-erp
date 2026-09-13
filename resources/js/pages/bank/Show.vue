<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

interface StatementLine { id: string; posted_on: string; amount: string; reference: string | null; description: string | null }
interface LedgerLine { journal_line_id: string; journal_id: string; journal_number: string | null; posting_date: string; amount: string; reference: string | null; receipt_number: string | null }
const props = defineProps<{ account: { id: string; bank_name: string; account_no_masked: string; currency: string }; asOf: string; unmatched: { statement_lines: StatementLine[]; journal_lines: LedgerLine[] } }>();

const asOf = ref(props.asOf);
const selectedLine = ref<string | null>(null);
const selectedJournalLines = ref<string[]>([]);
const upload = useForm<{ file: File | null }>({ file: null });
const explainForm = useForm({ reason: '' });
const matchForm = useForm({ journal_line_ids: [] as string[] });

function match(): void {
    if (!selectedLine.value) return;
    matchForm.journal_line_ids = selectedJournalLines.value;
    matchForm.post(`/bank/lines/${selectedLine.value}/match`, { preserveScroll: true, onSuccess: () => { selectedLine.value = null; selectedJournalLines.value = []; } });
}

function explain(): void {
    if (!selectedLine.value) return;
    explainForm.post(`/bank/lines/${selectedLine.value}/explain`, { preserveScroll: true, onSuccess: () => { selectedLine.value = null; explainForm.reset(); } });
}
</script>

<template>
    <AppLayout :title="account.bank_name">
        <PageHeader eyebrow="Bank reconciliation" :title="`${account.bank_name} ${account.account_no_masked}`" :description="`Unmatched as of ${asOf}. Pick a statement line, then the ledger lines it pays, or explain it.`">
            <form class="flex gap-2" @submit.prevent="router.get(`/bank/${props.account.id}`, { as_of: asOf }, { preserveState: true })">
                <Input v-model="asOf" type="date" class="w-40" aria-label="As of" /><Button type="submit" variant="ghost">Show</Button>
            </form>
            <Link href="/bank" class="text-sm text-blueprint hover:underline">All accounts</Link>
        </PageHeader>
        <FormBanner />
        <Card class="mb-6 flex flex-wrap items-end gap-4">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="upload.post(`/bank/${props.account.id}/statements`, { forceFormData: true, onSuccess: () => upload.reset() })">
                <label class="grid gap-1 text-sm">Statement CSV <input type="file" accept=".csv,text/csv" class="text-sm text-ivory-dim" @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                <Button type="submit" :disabled="!upload.file || upload.processing">Import</Button>
            </form>
            <Button variant="ghost" @click="router.post(`/bank/${props.account.id}/auto-match`, {}, { preserveScroll: true })">Match automatically</Button>
        </Card>
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h2 class="mb-2 text-lg font-semibold">Statement lines</h2>
                <Table>
                    <TableHeader><TableRow><TableHead /><TableHead>Date</TableHead><TableHead>Details</TableHead><TableHead class="text-right">Amount</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="line in unmatched.statement_lines" :key="line.id" :class="selectedLine === line.id ? 'bg-surface-raised' : ''">
                            <TableCell><input v-model="selectedLine" type="radio" :value="line.id" class="accent-brick" :aria-label="`Select statement line ${line.posted_on} ${line.amount}`" /></TableCell>
                            <TableCell>{{ line.posted_on }}</TableCell><TableCell class="text-ivory-dim">{{ line.reference }} {{ line.description }}</TableCell>
                            <TableCell class="text-right font-mono tabular-nums">{{ line.amount }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="unmatched.statement_lines.length === 0" :colspan="4">Every statement line is matched or explained.</TableEmpty>
                    </TableBody>
                </Table>
                <div v-if="selectedLine" class="mt-3 flex flex-wrap gap-2">
                    <Input v-model="explainForm.reason" placeholder="Why it has no ledger line (e.g. bank fee)" class="w-72" aria-label="Explanation" />
                    <Button variant="ghost" @click="explain">Explain</Button>
                </div>
            </div>
            <div>
                <h2 class="mb-2 text-lg font-semibold">Ledger lines</h2>
                <Table>
                    <TableHeader><TableRow><TableHead /><TableHead>Date</TableHead><TableHead>Journal</TableHead><TableHead class="text-right">Amount</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="line in unmatched.journal_lines" :key="line.journal_line_id">
                            <TableCell><input v-model="selectedJournalLines" type="checkbox" :value="line.journal_line_id" class="accent-brick" :aria-label="`Select ledger line ${line.posting_date} ${line.amount}`" /></TableCell>
                            <TableCell>{{ line.posting_date }}</TableCell>
                            <TableCell><Link :href="`/accounting/journals/${line.journal_id}`" class="font-mono text-blueprint hover:underline">{{ line.journal_number }}</Link> <span class="text-ivory-dim">{{ line.receipt_number }} {{ line.reference }}</span></TableCell>
                            <TableCell class="text-right font-mono tabular-nums">{{ line.amount }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="unmatched.journal_lines.length === 0" :colspan="4">No unmatched ledger lines.</TableEmpty>
                    </TableBody>
                </Table>
                <Button class="mt-3" :disabled="!selectedLine || selectedJournalLines.length === 0 || matchForm.processing" @click="match">Match selected</Button>
            </div>
        </div>
    </AppLayout>
</template>
