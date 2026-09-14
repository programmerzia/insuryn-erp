<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import Drawer from '@/components/ui/Drawer.vue';
import { basisSentence, changeRows, changeSentence, type EndorsementRatingData } from '@/lib/endorsement';
import { HttpError, requestJson } from '@/lib/http';
import { type PreviewResult, previewJournal } from '@/lib/preview';
import { type FormValues, formFields, initialValues, type Locale, localProblems, problemMessage, ratingKey, type RiskFieldDefinition, riskInputs } from '@/lib/riskForm';

/**
 * Phase 3 design §2 step 5 (slice R7): endorse a rated policy by changing its risk. The form comes from the product's risk schema, prefilled with the risk in force;
 * the change is re-rated on the server as the officer types (on the policy's own tariff unless the product uses the current one) and shown before → after;
 * Review asks for the journal the endorsement would post, and only Confirm posts it.
 */
const props = defineProps<{
    policyId: string; title: string; currency: string; locale: Locale; inception: string; expiry: string; today: string;
    schema: RiskFieldDefinition[]; inputs: Record<string, unknown>; coverages: { code: string; name_en: string; name_bn: string; mandatory: boolean }[]; chosen: string[];
}>();
const open = defineModel<boolean>('open', { default: false });

const effectiveDate = ref(props.today < props.inception ? props.inception : props.today > props.expiry ? props.expiry : props.today);
const reason = ref('');
const values = ref<FormValues>(initialValues(props.schema, props.inputs));
const chosen = ref<string[]>(props.chosen.filter((c) => props.coverages.some((o) => o.code === c && !o.mandatory)));
const fields = computed(() => formFields(props.schema, props.locale, 'proposal'));
const optional = computed(() => props.coverages.filter((c) => !c.mandatory));
const payload = computed(() => ({ effective_date: effectiveDate.value, risk_inputs: riskInputs(props.schema, values.value), coverages: chosen.value }));

const rating = ref<EndorsementRatingData | null>(null);
const problem = ref<string | null>(null);
const serverErrors = ref<Record<string, string>>({});
const errors = ref<Record<string, string>>({});
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;

watch(() => [open.value, effectiveDate.value, ratingKey({ product_id: '', inception: effectiveDate.value, risk_inputs: payload.value.risk_inputs, coverages: chosen.value })], () => {
    clearTimeout(timer);
    if (!open.value || Object.keys(localProblems(props.schema, values.value, 'proposal')).length > 0) {
        rating.value = null;
        return;
    }
    timer = setTimeout(() => void rerate(), 400);
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

async function rerate(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    busy.value = true;
    try {
        rating.value = (await requestJson<{ rating: EndorsementRatingData }>('POST', `/policies/${props.policyId}/endorsement-rating`, payload.value, controller.signal)).rating;
        problem.value = null;
        serverErrors.value = {};
    } catch (error) {
        if (!(error instanceof HttpError)) return;
        const body = error.body as { reason?: string; message?: string; errors?: Record<string, string | string[]> } | null;
        rating.value = null;
        if (body?.reason === 'RISK_INPUTS_INVALID') {
            serverErrors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, code]) => [k, problemMessage(String(code), props.schema.find((f) => f.key === k))]));
            problem.value = null;
        } else {
            serverErrors.value = {};
            problem.value = body?.message ?? 'The change could not be re-rated. Check the details.';
        }
    } finally {
        busy.value = false;
    }
}

const text = (key: string) => {
    const value = values.value[key];
    return typeof value === 'string' ? value : '';
};
const set = (key: string, value: string | undefined) => (values.value = { ...values.value, [key]: value ?? '' });
const fieldError = (key: string) => serverErrors.value[key] ?? localProblems(props.schema, values.value, 'proposal')[key];

const preview = ref<PreviewResult | null>(null);
const previewOpen = ref(false);
const posting = ref(false);
const data = () => ({ ...payload.value, reason: reason.value });
async function review(): Promise<void> {
    errors.value = {};
    const outcome = await previewJournal(`/policies/${props.policyId}/endorse-risk`, data());
    if (!outcome.ok) {
        errors.value = outcome.errors;
        return;
    }
    preview.value = outcome.result;
    previewOpen.value = true;
}
function post(): void {
    router.post(`/policies/${props.policyId}/endorse-risk`, data() as never, {
        preserveScroll: true,
        onStart: () => (posting.value = true),
        onSuccess: () => (open.value = false),
        onError: (e) => (errors.value = e as Record<string, string>),
        onFinish: () => {
            posting.value = false;
            previewOpen.value = false;
        },
    });
}
</script>

<template>
    <Drawer v-model:open="open" :title="`Endorse ${title}`" width="w-[720px]">
        <FormLayout submit-label="Review and post" :dirty="reason !== ''" :processing="posting" :error="errors.form" @submit="review" @cancel="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field id="endorse_effective_date" label="Effective from" :error="errors.effective_date"><DateInput id="endorse_effective_date" v-model="effectiveDate" /></Field>
                <Field id="endorse_reason" label="Reason" :error="errors.reason"><TextInput id="endorse_reason" v-model="reason" /></Field>
            </div>
            <h3 class="mt-2 text-ui font-medium">Risk details</h3>
            <template v-for="field in fields" :key="field.key">
                <Field v-if="field.type === 'boolean'" :id="`endorse_${field.key}`" :label="field.label" optional>
                    <label class="flex items-center gap-2 text-ui"><input :id="`endorse_${field.key}`" v-model="values[field.key]" type="checkbox" class="size-3.5 accent-accent" />Yes</label>
                </Field>
                <Field v-else :id="`endorse_${field.key}`" :label="field.label" :optional="!field.required" :hint="field.hint ?? undefined" :error="fieldError(field.key)">
                    <SelectInput v-if="field.type === 'select'" :id="`endorse_${field.key}`" :model-value="text(field.key)" placeholder="Choose" :options="field.options" @update:model-value="set(field.key, $event)" />
                    <MoneyInput v-else-if="field.type === 'money'" :id="`endorse_${field.key}`" :model-value="text(field.key)" @update:model-value="set(field.key, $event)" />
                    <DateInput v-else-if="field.type === 'date'" :id="`endorse_${field.key}`" :model-value="text(field.key)" @update:model-value="set(field.key, $event)" />
                    <TextInput v-else :id="`endorse_${field.key}`" :model-value="text(field.key)" :maxlength="field.maxLength ?? undefined" :inputmode="field.type === 'integer' ? 'numeric' : 'text'" @update:model-value="set(field.key, String($event))" />
                </Field>
            </template>
            <fieldset v-if="optional.length" class="grid gap-1">
                <legend class="mb-1 text-ui font-medium">Optional cover</legend>
                <label v-for="c in optional" :key="c.code" class="flex items-center gap-2 text-ui"><input v-model="chosen" type="checkbox" :value="c.code" class="size-3.5 accent-accent" />{{ locale === 'bn' ? c.name_bn : c.name_en }}</label>
            </fieldset>

            <section class="rounded-panel border border-line bg-surface-2 p-3" aria-live="polite" aria-label="Premium change">
                <h3 class="mb-2 text-ui font-medium">Premium change ({{ currency }})</h3>
                <p v-if="problem" class="text-ui text-danger" role="alert">{{ problem }}</p>
                <p v-else-if="!rating" class="text-ui text-ink-2">{{ busy ? 'Re-rating the change…' : 'Complete the risk details to see the change.' }}</p>
                <template v-else>
                    <table class="w-full text-ui" :class="{ 'opacity-60': busy }">
                        <thead class="text-ink-2"><tr><th class="py-1 text-left font-medium" /><th class="py-1 text-right font-medium">Now</th><th class="py-1 text-right font-medium">Re-rated</th><th class="py-1 text-right font-medium">Charged</th></tr></thead>
                        <tbody>
                            <tr v-for="row in changeRows(rating)" :key="row.label" class="border-t border-line">
                                <td class="py-1 pr-2">{{ row.label }}</td><td class="py-1 text-right tabular-nums">{{ row.before }}</td><td class="py-1 text-right tabular-nums">{{ row.after }}</td><td class="py-1 text-right font-medium tabular-nums">{{ row.change }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-ui">{{ changeSentence(rating, currency) }}</p>
                    <p class="mt-1 text-dense text-ink-2">{{ basisSentence(rating) }}</p>
                </template>
            </section>
        </FormLayout>
        <JournalPreviewDialog v-model:open="previewOpen" :result="preview" :title="`Post the endorsement of ${title}?`" confirm-label="Post endorsement" :currency="currency" :processing="posting" @confirm="post" />
    </Drawer>
</template>
