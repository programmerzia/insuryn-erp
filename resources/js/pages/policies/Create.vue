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
    entity: { id: string; code: string; name: string; currency: string };
    branches: { id: string; code: string; name: string }[];
    products: { id: string; code: string; name: string }[];
    parties: { id: string; display_name: string }[];
    agents: { id: string; code: string; display_name: string }[];
}>();

const form = useForm({
    branch_id: props.branches[0]?.id ?? '', product_id: '', policyholder_party_id: '', agent_id: '', inception: '', premium: '', installment_count: 1,
    payers: [] as { party_id: string; share_percent: string }[],
});
const partyOptions = props.parties.map((p) => ({ value: p.id, label: p.display_name }));
</script>

<template>
    <AppLayout title="New quote">
        <PageHeader :eyebrow="`${entity.code} · ${entity.currency}`" title="New quote" description="Premium as charged under the product's tax profile. Issue the quote from its page." />
        <Card class="max-w-3xl">
            <FormBanner />
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="form.post('/policies')">
                <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` }))" /></Field>
                <Field id="product_id" label="Product" :error="form.errors.product_id"><SelectInput id="product_id" v-model="form.product_id" placeholder="Choose a product" :options="products.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" /></Field>
                <Field id="policyholder_party_id" label="Policyholder" :error="form.errors.policyholder_party_id"><SelectInput id="policyholder_party_id" v-model="form.policyholder_party_id" placeholder="Choose a party" :options="partyOptions" /></Field>
                <Field id="agent_id" label="Agent" :error="form.errors.agent_id"><SelectInput id="agent_id" v-model="form.agent_id" placeholder="Direct business" :options="agents.map((a) => ({ value: a.id, label: `${a.code} · ${a.display_name}` }))" /></Field>
                <Field id="inception" label="Inception" :error="form.errors.inception"><Input id="inception" v-model="form.inception" type="date" /></Field>
                <Field id="premium" :label="`Premium (${entity.currency})`" :error="form.errors.premium"><Input id="premium" v-model="form.premium" inputmode="decimal" placeholder="120,000.00" /></Field>
                <Field id="installment_count" label="Installments" :error="form.errors.installment_count"><Input id="installment_count" v-model.number="form.installment_count" type="number" min="1" max="12" /></Field>
                <fieldset class="grid gap-2 sm:col-span-2">
                    <legend class="text-sm font-medium">Payers <span class="text-ivory-dim">(leave empty when the policyholder pays everything)</span></legend>
                    <div v-for="(payer, index) in form.payers" :key="index" class="flex gap-2">
                        <SelectInput v-model="payer.party_id" placeholder="Choose a party" :options="partyOptions" :aria-label="`Payer ${index + 1}`" />
                        <Input v-model="payer.share_percent" inputmode="decimal" placeholder="%" class="w-24" :aria-label="`Share ${index + 1} in percent`" />
                        <Button variant="ghost" @click="form.payers.splice(index, 1)">Remove</Button>
                    </div>
                    <Button variant="ghost" class="justify-self-start" @click="form.payers.push({ party_id: '', share_percent: '' })">Add payer</Button>
                </fieldset>
                <Button type="submit" :disabled="form.processing" class="justify-self-start">Create quote</Button>
            </form>
        </Card>
    </AppLayout>
</template>
