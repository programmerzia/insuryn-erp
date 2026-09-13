<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import Stepper from '@/components/forms/Stepper.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney } from '@/lib/money';
import { savePreference, usePreferences } from '@/lib/preferences';
import { toast } from '@/lib/toasts';

/** Brief §4 long flow: a quote in four steps (who and what · premium · payers · review) with a summary rail and drafts. Issuing happens on the policy. */
const props = defineProps<{
    entity: { id: string; code: string; name: string; currency: string };
    branches: { id: string; code: string; name: string }[];
    products: { id: string; code: string; name: string }[];
    parties: { id: string; display_name: string }[];
    agents: { id: string; code: string; display_name: string }[];
}>();

const DRAFT = 'quote-create';
const preferences = usePreferences();
const form = useForm({
    branch_id: props.branches[0]?.id ?? '', product_id: '', policyholder_party_id: '', agent_id: '', inception: '', premium: '', installment_count: 1,
    payers: [] as { party_id: string; share_percent: string }[],
});
const holder = ref<LookupResult | null>(null);
const agent = ref<LookupResult | null>(null);
const step = ref(0);
const steps = [{ id: 'who', label: 'Customer and product' }, { id: 'premium', label: 'Premium' }, { id: 'payers', label: 'Payers' }, { id: 'review', label: 'Review' }];
const product = computed(() => props.products.find((p) => p.id === form.product_id));
const perInstallment = computed(() => {
    const premium = parseMoney(form.premium);
    return premium === null || form.installment_count < 1 ? null : formatMinor(premium / BigInt(form.installment_count));
});
const shareTotal = computed(() => form.payers.reduce((sum, p) => sum + (parseMoney(p.share_percent) ?? 0n), 0n));
const partyName = (id: string) => props.parties.find((p) => p.id === id)?.display_name ?? '';

onMounted(() => {
    const draft = preferences.drafts[DRAFT] as (Record<string, unknown> & { holder?: LookupResult | null; agent?: LookupResult | null }) | null | undefined;
    if (draft) {
        form.defaults({ ...form.data(), ...(draft as object) });
        form.reset();
        holder.value = draft.holder ?? null;
        agent.value = draft.agent ?? null;
        toast('Your saved draft is back.');
    }
});

function fail(field: 'product_id' | 'policyholder_party_id' | 'inception' | 'premium' | 'payers', message: string): void {
    form.setError(field, message);
}

function next(): void {
    form.clearErrors();
    if (step.value === 0) {
        if (!form.policyholder_party_id) return fail('policyholder_party_id', 'Choose the policyholder, or press Ctrl+N to add a new customer.');
        if (!form.product_id) return fail('product_id', 'Choose the product to quote.');
    }
    if (step.value === 1) {
        if (!form.inception) return fail('inception', 'Enter the date the cover starts.');
        if ((parseMoney(form.premium) ?? 0n) <= 0n) return fail('premium', `Enter the gross premium in ${props.entity.currency}, like 120,000.00.`);
    }
    if (step.value === 2 && form.payers.length > 0 && shareTotal.value !== 10000n) {
        return fail('payers', `Payer shares add up to ${formatMinor(shareTotal.value)}%; they must add up to 100%.`);
    }
    if (step.value < steps.length - 1) {
        step.value++;
        return;
    }
    form.post('/policies', { onSuccess: () => savePreference(`drafts.${DRAFT}`, null, 0) });
}

function saveDraft(): void {
    savePreference(`drafts.${DRAFT}`, { ...form.data(), holder: holder.value, agent: agent.value }, 0);
    toast('Draft saved. It will be here when you come back.');
}
</script>

<template>
    <AppLayout help="policies" title="New quote">
        <h1 class="mb-4 text-title font-semibold">New quote</h1>
        <Stepper :steps="steps" :current="step" @go="step = $event">
            <FormLayout :submit-label="step < steps.length - 1 ? 'Continue' : 'Create quote'" cancel-href="/policies" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" drafts @submit="next" @save-draft="saveDraft">
                <template v-if="step === 0">
                    <Field id="policyholder_party_id" label="Policyholder" :error="form.errors.policyholder_party_id" hint="Name or tax ID. Ctrl+N adds a new customer.">
                        <LookupInput v-model="form.policyholder_party_id" type="customer" creatable :initial="holder" @selected="holder = $event" />
                    </Field>
                    <Field id="product_id" label="Product" :error="form.errors.product_id"><SelectInput id="product_id" v-model="form.product_id" placeholder="Choose a product" :options="products.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" /></Field>
                    <Field id="agent_id" label="Agent" optional :error="form.errors.agent_id" hint="Leave empty for direct business."><LookupInput v-model="form.agent_id" type="agent" :initial="agent" @selected="agent = $event" /></Field>
                    <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: b.name }))" /></Field>
                </template>
                <template v-else-if="step === 1">
                    <Field id="inception" label="Cover starts" :error="form.errors.inception" hint="t for today, +7 for a week from today."><DateInput v-model="form.inception" /></Field>
                    <Field id="premium" :label="`Gross premium (${entity.currency})`" :error="form.errors.premium" hint="As charged, under the product's tax rules."><MoneyInput v-model="form.premium" /></Field>
                    <Field id="installment_count" label="Installments" :error="form.errors.installment_count">
                        <SelectInput id="installment_count" :model-value="String(form.installment_count)" :options="['1', '2', '3', '4', '6', '12'].map((n) => ({ value: n, label: n === '1' ? 'Paid at once' : `${n} installments` }))" @update:model-value="(v) => (form.installment_count = Number(v))" />
                    </Field>
                </template>
                <template v-else-if="step === 2">
                    <p class="text-ui text-ink-2">Leave this empty when the policyholder pays everything. Shares must add up to 100%.</p>
                    <div v-for="(payer, index) in form.payers" :key="index" class="grid grid-cols-[minmax(0,1fr)_110px_32px] items-center gap-2">
                        <SelectInput v-model="payer.party_id" placeholder="Choose a payer" :options="parties.map((p) => ({ value: p.id, label: p.display_name }))" :aria-label="`Payer ${index + 1}`" />
                        <input v-model="payer.share_percent" inputmode="decimal" class="h-8 rounded-control border border-line-control bg-surface px-2 text-right text-body tabular-nums" placeholder="%" :aria-label="`Share of payer ${index + 1} in percent`" />
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2" :aria-label="`Remove payer ${index + 1}`" @click="form.payers.splice(index, 1)"><X :size="14" :stroke-width="1.5" /></button>
                    </div>
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 justify-self-start rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" @click="form.payers.push({ party_id: '', share_percent: '' })"><Plus :size="16" :stroke-width="1.5" />Add a payer</button>
                    <p v-if="form.errors.payers" class="text-dense text-danger" role="alert">{{ form.errors.payers }}</p>
                </template>
                <template v-else>
                    <p class="text-ui text-ink-2">Creating the quote posts nothing. Issue it from the policy page when the customer accepts.</p>
                </template>
            </FormLayout>
            <template #summary>
                <dl class="grid gap-2 text-ui">
                    <div><dt class="text-dense text-ink-2">Policyholder</dt><dd>{{ holder?.label ?? 'Not chosen yet' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Product</dt><dd>{{ product ? `${product.code} · ${product.name}` : '—' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Agent</dt><dd>{{ agent?.label ?? 'Direct business' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Cover starts</dt><dd>{{ formatDate(form.inception) || '—' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Gross premium ({{ entity.currency }})</dt><dd class="tabular-nums">{{ formatMoney(form.premium) || '—' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Installments</dt><dd class="tabular-nums">{{ form.installment_count }}<template v-if="perInstallment && form.installment_count > 1"> × about {{ perInstallment }}</template></dd></div>
                    <div v-if="form.payers.length"><dt class="text-dense text-ink-2">Payers</dt><dd v-for="p in form.payers" :key="p.party_id">{{ partyName(p.party_id) || 'Not chosen' }} · {{ p.share_percent || 0 }}%</dd></div>
                </dl>
            </template>
        </Stepper>
    </AppLayout>
</template>
