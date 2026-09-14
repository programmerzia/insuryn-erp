<script setup lang="ts">
import GeneratedDocuments from '@/components/object/GeneratedDocuments.vue';
import type { DocumentGeneration } from '@/components/object/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import RatingBreakdown from '@/components/rating/RatingBreakdown.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';
import { HttpError, requestJson } from '@/lib/http';
import { savePreference, usePreferences } from '@/lib/preferences';
import {
    type FormValues, formFields, initialValues, localProblems, problemMessage, type ProductVersionOption, ratingKey, type RatingResultData, riskInputs, versionOn,
} from '@/lib/riskForm';
import type { SharedProps } from '@/types/shared';

/**
 * Phase 3 design §6 "Quote workbench (risk form left, live premium breakdown right with EN/BN labels)" (slice R4). The risk form comes from the product
 * version's risk schema; the premium is rated on the server as the officer types (debounced, nothing saved) and shown line by line in the user's
 * language. Save draft keeps the work; Issue quotation numbers it and freezes the premium for the validity period.
 */
interface Product { id: string; code: string; name: string; versions: ProductVersionOption[] }
interface QuotationData {
    id: string; number: string | null; status: string; branch_id: string; product_id: string; product_version_id: string; inception: string; valid_until: string | null;
    customer: LookupResult | null; producer: LookupResult | null; risk_inputs: Record<string, unknown>; coverages: string[]; rating_result: RatingResultData | null;
    producer_eligible: boolean | null; producer_eligibility_note: string | null; decline_reason: string | null; issued_at: string | null; proposal_id: string | null; renewal_of?: { id: string; number: string } | null;
}
const props = defineProps<{
    quotation: QuotationData | null; products: Product[]; branches: { id: string; code: string; name: string }[]; currency: string; today: string; validDays: number;
    can: { edit: boolean; issue: boolean; decline: boolean; convert: boolean };
    /** Printing the issued quotation (null before the quotation is saved). */
    generation?: DocumentGeneration | null;
}>();

const page = usePage<SharedProps>();
const preferences = usePreferences();
const locale = computed(() => preferences.locale);
const q = props.quotation;

const state = reactive({
    branch_id: q?.branch_id ?? preferences.branch_id ?? props.branches[0]?.id ?? '',
    product_id: q?.product_id ?? '',
    inception: q?.inception ?? props.today,
    customer_party_id: q?.customer?.id ?? '',
    producer_id: q?.producer?.id ?? '',
    coverages: [...(q?.coverages ?? [])] as string[],
});
const customer = ref<LookupResult | null>(q?.customer ?? null);
const producer = ref<LookupResult | null>(q?.producer ?? null);
const product = computed(() => props.products.find((p) => p.id === state.product_id) ?? null);
const version = computed(() => (product.value ? versionOn(product.value.versions, state.inception) : null));
const schema = computed(() => version.value?.risk_schema ?? []);
const fields = computed(() => formFields(schema.value, locale.value));
const values = ref<FormValues>(initialValues(schema.value, q?.risk_inputs));
watch(schema, (next, previous) => {
    if (next !== previous) values.value = { ...initialValues(next, null), ...Object.fromEntries(Object.entries(values.value).filter(([k]) => next.some((f) => f.key === k))) };
});
const optionalCoverages = computed(() => (version.value?.coverages ?? []).filter((c) => !c.mandatory));

// Live rating (debounced; only when the browser finds no problem; stale answers ignored).
const result = ref<RatingResultData | null>(q?.rating_result ?? null);
const serverErrors = ref<Record<string, string>>({});
const ratingProblem = ref<string | null>(null);
const rating = ref(false);
const touched = ref(q !== null);
const readOnly = computed(() => !props.can.edit);
const problems = computed(() => (touched.value ? localProblems(schema.value, values.value) : {}));
const payload = computed(() => ({
    branch_id: state.branch_id, product_id: state.product_id, inception: state.inception, customer_party_id: state.customer_party_id || null, producer_id: state.producer_id || null,
    risk_inputs: riskInputs(schema.value, values.value), coverages: state.coverages.filter((c) => optionalCoverages.value.some((o) => o.code === c)),
}));
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
let lastKey = q?.rating_result ? ratingKey(payload.value) : '';

watch(() => ratingKey(payload.value), (key) => {
    if (readOnly.value || !version.value) return;
    clearTimeout(timer);
    if (Object.keys(localProblems(schema.value, values.value)).length > 0) {
        result.value = null;
        return;
    }
    if (key === lastKey) return;
    timer = setTimeout(() => void rate(key), 400);
});
onBeforeUnmount(() => clearTimeout(timer));

async function rate(key: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    rating.value = true;
    try {
        const response = await requestJson<{ result: RatingResultData }>('POST', '/quotations/rate', payload.value, controller.signal);
        result.value = response.result;
        serverErrors.value = {};
        ratingProblem.value = null;
        lastKey = key;
    } catch (error) {
        if (!(error instanceof HttpError)) return;
        const body = error.body as { reason?: string; message?: string; errors?: Record<string, string | string[]> } | null;
        result.value = null;
        if (body?.reason === 'RISK_INPUTS_INVALID') {
            serverErrors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, code]) => [k, problemMessage(String(code), schema.value.find((f) => f.key === k))]));
            ratingProblem.value = null;
        } else {
            serverErrors.value = {};
            ratingProblem.value = error.status === 403 ? 'You cannot quote for this branch.' : (body?.message ?? 'The premium could not be worked out. Check the details.');
        }
    } finally {
        rating.value = false;
    }
}

const text = (key: string): string => {
    const value = values.value[key];
    return typeof value === 'string' ? value : '';
};
function set(key: string, value: string | undefined): void {
    values.value = { ...values.value, [key]: value ?? '' };
    touched.value = true;
}
const errorFor = (key: string) => serverErrors.value[key] ?? problems.value[key];
const formErrors = computed(() => page.props.errors as Record<string, string>);

// Save, issue, decline.
const saving = ref(false);
function save(intent: 'save' | 'issue'): void {
    touched.value = true;
    if (intent === 'issue' && Object.keys(localProblems(schema.value, values.value)).length > 0) return;
    const data = { ...payload.value, intent };
    // A fresh page state after saving: the page reads the quotation once (`q`), so a new quote that gets its id, or a draft that gets its number, must
    // remount rather than keep the old state (the flow audit found "make proposal" doing nothing right after issuing a new quote).
    const options = { preserveScroll: true, preserveState: false, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) };
    if (q) router.put(`/quotations/${q.id}`, data, options);
    else router.post('/quotations', data, options);
}
const declineOpen = ref(false);
const declineForm = useForm({ reason: '' });
function decline(): void {
    if (q) declineForm.post(`/quotations/${q.id}/decline`, { preserveScroll: true, onSuccess: () => (declineOpen.value = false) });
}
async function makeProposal(): Promise<void> {
    if (q && (await confirmAction({ title: `Make a proposal from ${q.number}?`, body: 'The customer accepts this quotation. The proposal keeps its premium; KYC, documents and underwriting follow.', confirmLabel: 'Make proposal' }))) {
        router.post(`/quotations/${q.id}/proposal`, {}, { preserveScroll: true });
    }
}
const title = computed(() => q?.number ?? (q ? 'Draft quotation' : 'New quote'));
const validUntil = computed(() => q?.valid_until ?? null);
</script>

<template>
    <AppLayout help="quotes" :title="title">
        <div data-tour="quote-workbench" class="px-6 py-4">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <Link href="/quotations" class="text-ui text-ink-2 hover:text-ink">Quotes</Link>
                <span class="text-ink-2" aria-hidden="true">/</span>
                <h1 class="text-title font-semibold">{{ title }}</h1>
                <StatusBadge v-if="q" :status="q.status" />
                <p v-if="validUntil" class="text-ui text-ink-2">Valid until {{ formatDate(validUntil) }}</p>
                <p v-if="q?.renewal_of" class="text-ui text-ink-2">Renewal of <Link :href="`/policies/${q.renewal_of.id}`" class="text-accent-text hover:underline">{{ q.renewal_of.number }}</Link></p>
                <div class="ml-auto flex gap-2">
                    <Button v-if="can.edit" variant="secondary" :disabled="saving || !state.product_id" @click="save('save')">Save draft</Button>
                    <Button v-if="can.issue" :disabled="saving || !result" @click="save('issue')">Issue quotation</Button>
                    <Button v-if="can.decline" variant="ghost" @click="declineOpen = true">Decline</Button>
                    <Button v-if="can.convert" @click="makeProposal">Customer accepts: make proposal</Button>
                    <Link v-if="q?.proposal_id" :href="`/proposals/${q.proposal_id}`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Open proposal</Link>
                </div>
            </div>
            <p v-if="formErrors.form" class="mb-4 border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ formErrors.form }}</p>
            <p v-if="q?.decline_reason" class="mb-4 border-l-2 border-warn pl-3 text-ui">Declined: {{ q.decline_reason }}</p>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,560px)_minmax(280px,360px)]">
                <fieldset class="grid min-w-0 gap-4" :disabled="readOnly">
                    <div class="grid grid-cols-2 gap-3">
                        <Field id="branch_id" label="Branch" :error="formErrors.branch_id">
                            <SelectInput id="branch_id" v-model="state.branch_id" :options="branches.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` }))" />
                        </Field>
                        <Field id="inception" label="Cover starts" :error="formErrors.inception" hint="The premium uses the tariff in force that day.">
                            <DateInput id="inception" v-model="state.inception" />
                        </Field>
                    </div>
                    <Field id="product_id" label="Product" :error="formErrors.product_id">
                        <SelectInput id="product_id" v-model="state.product_id" placeholder="Choose a product" :options="products.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" />
                    </Field>
                    <Field id="customer_party_id" label="Customer" optional hint="Needed to issue. Ctrl+N creates a customer.">
                        <LookupInput id="customer_party_id" v-model="state.customer_party_id" type="customer" creatable :initial="customer" @selected="customer = $event" />
                    </Field>
                    <Field id="producer_id" label="Producer" optional hint="Leave empty for direct business.">
                        <LookupInput id="producer_id" v-model="state.producer_id" type="agent" :initial="producer" @selected="producer = $event" />
                    </Field>
                    <p v-if="q?.producer_eligible === false" class="border-l-2 border-warn pl-3 text-ui">{{ q.producer_eligibility_note }} The proposal will be referred to underwriting.</p>

                    <template v-if="version">
                        <h2 class="mt-2 text-section font-semibold">Risk details</h2>
                        <template v-for="field in fields" :key="field.key">
                            <Field v-if="field.type === 'boolean'" :id="`risk_${field.key}`" :label="field.label" optional>
                                <label class="flex items-center gap-2 text-ui"><input :id="`risk_${field.key}`" v-model="values[field.key]" type="checkbox" class="size-3.5 accent-accent" />Yes</label>
                            </Field>
                            <Field v-else :id="`risk_${field.key}`" :label="field.label" :optional="!field.required" :hint="field.hint ?? undefined" :error="errorFor(field.key)">
                                <SelectInput v-if="field.type === 'select'" :id="`risk_${field.key}`" :model-value="text(field.key)" placeholder="Choose" :options="field.options" @update:model-value="set(field.key, $event)" />
                                <MoneyInput v-else-if="field.type === 'money'" :id="`risk_${field.key}`" :model-value="text(field.key)" @update:model-value="set(field.key, $event)" />
                                <DateInput v-else-if="field.type === 'date'" :id="`risk_${field.key}`" :model-value="text(field.key)" @update:model-value="set(field.key, $event)" />
                                <TextInput v-else :id="`risk_${field.key}`" :model-value="text(field.key)" :maxlength="field.maxLength ?? undefined" :inputmode="field.type === 'integer' ? 'numeric' : 'text'" @update:model-value="set(field.key, String($event))" />
                            </Field>
                        </template>
                        <fieldset v-if="optionalCoverages.length" class="grid gap-1">
                            <legend class="mb-1 text-ui font-medium">Optional cover</legend>
                            <label v-for="c in optionalCoverages" :key="c.code" class="flex items-center gap-2 text-ui">
                                <input v-model="state.coverages" type="checkbox" :value="c.code" class="size-3.5 accent-accent" />{{ locale === 'bn' ? c.name_bn : c.name_en }}
                            </label>
                        </fieldset>
                    </template>
                    <p v-else-if="state.product_id" class="text-ui text-ink-2">This product has no version in force on {{ formatDate(state.inception) }}.</p>
                </fieldset>

                <aside class="h-fit rounded-panel border border-line bg-surface-2 p-4 lg:sticky lg:top-0" aria-label="Premium" aria-live="polite">
                    <div class="mb-2 flex items-center gap-2">
                        <h2 class="text-ui font-medium">Premium</h2>
                        <div class="ml-auto flex rounded-control border border-line text-dense" role="group" aria-label="Language of the breakdown">
                            <button type="button" class="px-2 py-0.5" :class="locale === 'en' ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="savePreference('locale', 'en', 0)">English</button>
                            <button type="button" class="px-2 py-0.5" :class="locale === 'bn' ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="savePreference('locale', 'bn', 0)">বাংলা</button>
                        </div>
                    </div>
                    <p v-if="!state.product_id" class="text-ui text-ink-2">Choose a product to see the premium.</p>
                    <p v-else-if="rating && !result" class="text-ui text-ink-2" role="status">Working out the premium…</p>
                    <p v-else-if="ratingProblem" class="text-ui text-danger" role="alert">{{ ratingProblem }}</p>
                    <p v-else-if="!result" class="text-ui text-ink-2">Complete the risk details to see the premium.</p>
                    <template v-else>
                        <RatingBreakdown :result="result" :locale="locale" :dimmed="rating" />
                        <p v-if="!q || q.status === 'draft'" class="mt-1 text-dense text-ink-2">Issuing keeps this premium for {{ validDays }} days.</p>
                    </template>
                </aside>
            </div>
            <section v-if="generation && (generation.actions.length || generation.history.length)" class="mt-6 max-w-[920px]" aria-label="Printed quotation">
                <h2 class="mb-2 text-section font-semibold">Printed quotation</h2>
                <GeneratedDocuments :generation="generation" />
            </section>
        </div>

        <Drawer v-model:open="declineOpen" :title="`Decline ${title}`">
            <form class="grid gap-4" @submit.prevent="decline">
                <p class="text-ui text-ink-2">The quotation stays on record as declined. Say why.</p>
                <Field id="decline_reason" label="Reason" :error="declineForm.errors.reason ?? (declineForm.errors as Record<string, string>).form">
                    <textarea id="decline_reason" v-model="declineForm.reason" rows="3" class="rounded-control border border-line-control bg-surface px-2 py-1.5 text-body text-ink" />
                </Field>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="declineOpen = false">Cancel</Button>
                    <Button type="submit" :disabled="declineForm.processing">Decline quotation</Button>
                </div>
            </form>
        </Drawer>
    </AppLayout>
</template>
