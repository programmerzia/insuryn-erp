<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    asOf: string;
    position: { rows: { agent_id: string; agent_code: string; collected: string; deposited: string; undeposited: string; gl: string; difference: string; oldest_undeposited_on: string | null; days_undeposited: number | null }[]; totals: Record<string, string> };
    agents: { id: string; code: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
}>();

const asOf = ref(props.asOf);
const form = useForm({ agent_id: '', amount: '', deposited_on: '', bank_account_id: '', reference: '' });
</script>

<template>
    <AppLayout title="Agent cash">
        <PageHeader eyebrow="Collections" title="Agent cash" :description="`Cash agents collected and have not yet deposited: ${position.totals.undeposited_minor} as of ${asOf}.`">
            <form class="flex gap-2" @submit.prevent="router.get('/agent-cash', { as_of: asOf }, { preserveState: true })">
                <Input v-model="asOf" type="date" aria-label="As of" /><Button type="submit" variant="ghost">Show</Button>
            </form>
        </PageHeader>
        <FormBanner />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Table>
                    <TableHeader><TableRow><TableHead>Agent</TableHead><TableHead class="text-right">Collected</TableHead><TableHead class="text-right">Deposited</TableHead><TableHead class="text-right">Undeposited</TableHead><TableHead class="text-right">Ledger</TableHead><TableHead class="text-right">Difference</TableHead><TableHead>Oldest held</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="row in position.rows" :key="row.agent_id">
                            <TableCell class="font-mono">{{ row.agent_code }}</TableCell>
                            <TableCell class="text-right font-mono tabular-nums">{{ row.collected }}</TableCell><TableCell class="text-right font-mono tabular-nums">{{ row.deposited }}</TableCell>
                            <TableCell class="text-right font-mono tabular-nums">{{ row.undeposited }}</TableCell><TableCell class="text-right font-mono tabular-nums">{{ row.gl }}</TableCell>
                            <TableCell class="text-right font-mono tabular-nums" :class="row.difference !== '0.00' ? 'text-brick-soft' : 'text-green'">{{ row.difference }}</TableCell>
                            <TableCell class="text-ivory-dim">{{ row.oldest_undeposited_on ? `${row.oldest_undeposited_on} (${row.days_undeposited} days)` : '—' }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="position.rows.length === 0" :colspan="7">No agent collections.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <Card>
                <h2 class="text-lg font-semibold">Record a deposit</h2>
                <form class="mt-4 grid gap-4" @submit.prevent="form.post('/agent-cash/deposits', { onSuccess: () => form.reset('amount', 'reference') })">
                    <Field id="deposit_agent" label="Agent" :error="form.errors.agent_id"><SelectInput id="deposit_agent" v-model="form.agent_id" placeholder="Choose an agent" :options="agents.map((a) => ({ value: a.id, label: a.code }))" /></Field>
                    <Field id="deposit_amount" label="Amount" :error="form.errors.amount"><Input id="deposit_amount" v-model="form.amount" inputmode="decimal" /></Field>
                    <Field id="deposited_on" label="Deposited on" :error="form.errors.deposited_on"><Input id="deposited_on" v-model="form.deposited_on" type="date" /></Field>
                    <Field id="deposit_bank" label="Bank account" :error="form.errors.bank_account_id"><SelectInput id="deposit_bank" v-model="form.bank_account_id" placeholder="Default bank" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" /></Field>
                    <Field id="deposit_reference" label="Deposit slip" :error="form.errors.reference"><Input id="deposit_reference" v-model="form.reference" /></Field>
                    <Button type="submit" :disabled="form.processing">Record deposit</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
