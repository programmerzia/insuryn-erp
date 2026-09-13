<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    entity: { code: string; currency: string };
    channels: string[];
    branches: { id: string; code: string; name: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    agents: { id: string; code: string }[];
    installments: { id: string; label: string; outstanding: string }[];
}>();

const form = useForm({
    branch_id: props.branches[0]?.id ?? '', channel: 'bank_transfer', amount: '', value_date: '', reference: '', bank_account_id: '',
    cheque_no: '', cheque_bank: '', cheque_date: '', collected_by_agent_id: '', allocations: [] as { installment_id: string; amount: string }[],
});
const installmentOptions = props.installments.map((i) => ({ value: i.id, label: `${i.label} · ${i.outstanding} outstanding` }));
</script>

<template>
    <AppLayout title="Record receipt">
        <PageHeader :eyebrow="`${entity.code} · ${entity.currency}`" title="Record receipt" description="Allocate to installments now; anything left over goes to suspense." />
        <Card class="max-w-3xl">
            <FormBanner />
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="form.post('/receipts')">
                <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` }))" /></Field>
                <Field id="channel" label="Channel" :error="form.errors.channel"><SelectInput id="channel" v-model="form.channel" :options="channels.map((c) => ({ value: c, label: c.replace('_', ' ') }))" /></Field>
                <Field id="amount" :label="`Amount (${entity.currency})`" :error="form.errors.amount"><Input id="amount" v-model="form.amount" inputmode="decimal" placeholder="50,000.00" /></Field>
                <Field id="value_date" label="Value date" :error="form.errors.value_date"><Input id="value_date" v-model="form.value_date" type="date" /></Field>
                <Field id="reference" label="Reference" :error="form.errors.reference"><Input id="reference" v-model="form.reference" /></Field>
                <Field id="bank_account_id" label="Bank account" :error="form.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="form.bank_account_id" placeholder="Default bank" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" /></Field>
                <template v-if="form.channel === 'cheque'">
                    <Field id="cheque_no" label="Cheque number" :error="form.errors.cheque_no"><Input id="cheque_no" v-model="form.cheque_no" /></Field>
                    <Field id="cheque_bank" label="Drawee bank" :error="form.errors.cheque_bank"><Input id="cheque_bank" v-model="form.cheque_bank" /></Field>
                    <Field id="cheque_date" label="Cheque date" :error="form.errors.cheque_date"><Input id="cheque_date" v-model="form.cheque_date" type="date" /></Field>
                </template>
                <Field v-if="form.channel === 'cash'" id="collected_by_agent_id" label="Collected by agent" hint="Agent collections must be allocated in full" :error="form.errors.collected_by_agent_id">
                    <SelectInput id="collected_by_agent_id" v-model="form.collected_by_agent_id" placeholder="Received by the company" :options="agents.map((a) => ({ value: a.id, label: a.code }))" />
                </Field>
                <fieldset class="grid gap-2 sm:col-span-2">
                    <legend class="text-ui font-medium">Allocations</legend>
                    <div v-for="(line, index) in form.allocations" :key="index" class="flex gap-2">
                        <SelectInput v-model="line.installment_id" placeholder="Choose an installment" :options="installmentOptions" :aria-label="`Installment ${index + 1}`" />
                        <Input v-model="line.amount" inputmode="decimal" placeholder="Amount" class="w-36" :aria-label="`Amount ${index + 1}`" />
                        <Button variant="ghost" @click="form.allocations.splice(index, 1)">Remove</Button>
                    </div>
                    <Button variant="ghost" class="justify-self-start" @click="form.allocations.push({ installment_id: '', amount: '' })">Add allocation</Button>
                </fieldset>
                <Button type="submit" :disabled="form.processing" class="justify-self-start">Record receipt</Button>
            </form>
        </Card>
    </AppLayout>
</template>
