<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    entity: { currency: string };
    accounts: { id: string; bank_name: string; account_no_masked: string; currency: string; status: string; gl_code: string; gl_name: string; unmatched_lines: number }[];
    glAccounts: { id: string; code: string; name: string }[];
}>();

const form = useForm({ gl_account_id: '', bank_name: '', account_no_masked: '', currency: props.entity.currency });
</script>

<template>
    <AppLayout title="Bank">
        <PageHeader eyebrow="Finance" title="Bank accounts" description="Each bank account posts to its own ledger account. Open one to import statements and match." />
        <FormBanner />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Table>
                    <TableHeader><TableRow><TableHead>Bank</TableHead><TableHead>Ledger account</TableHead><TableHead class="text-right">Unmatched lines</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="account in accounts" :key="account.id">
                            <TableCell><Link :href="`/bank/${account.id}`" class="text-blueprint hover:underline">{{ account.bank_name }} {{ account.account_no_masked }}</Link></TableCell>
                            <TableCell><span class="font-mono">{{ account.gl_code }}</span> {{ account.gl_name }}</TableCell>
                            <TableCell class="text-right tabular-nums" :class="account.unmatched_lines > 0 ? 'text-amber' : 'text-ivory-dim'">{{ account.unmatched_lines }}</TableCell>
                            <TableCell><StatusBadge :status="account.status" /></TableCell>
                        </TableRow>
                        <TableEmpty v-if="accounts.length === 0" :colspan="4">No bank accounts yet.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <Card>
                <h2 class="text-lg font-semibold">Add a bank account</h2>
                <form class="mt-4 grid gap-4" @submit.prevent="form.post('/bank', { onSuccess: () => form.reset('bank_name', 'account_no_masked') })">
                    <Field id="gl_account_id" label="Ledger account" :error="form.errors.gl_account_id"><SelectInput id="gl_account_id" v-model="form.gl_account_id" placeholder="Choose an asset account" :options="glAccounts.map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` }))" /></Field>
                    <Field id="bank_name" label="Bank" :error="form.errors.bank_name"><Input id="bank_name" v-model="form.bank_name" /></Field>
                    <Field id="account_no_masked" label="Account number (masked)" :error="form.errors.account_no_masked"><Input id="account_no_masked" v-model="form.account_no_masked" placeholder="****4471" /></Field>
                    <Field id="currency" label="Currency" :error="form.errors.currency"><Input id="currency" v-model="form.currency" maxlength="3" /></Field>
                    <Button type="submit" :disabled="form.processing">Add bank account</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
