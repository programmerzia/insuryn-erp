<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import Stepper from '@/components/forms/Stepper.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { savePreference, usePreferences } from '@/lib/preferences';
import { toast } from '@/lib/toasts';

const props = defineProps<{ policies: { id: string; number: string; display_name: string; inception: string; expiry: string }[] }>();

const DRAFT = 'drafts.claim-register';
const preferences = usePreferences();
const form = useForm({ policy_id: '', loss_date: '', reported_on: '', description: '' });
const policy = ref<LookupResult | null>(null);
const step = ref(0);
const steps = [{ id: 'policy', label: 'Policy' }, { id: 'loss', label: 'The loss' }, { id: 'review', label: 'Review' }];
const dateErrors = ref<Record<string, string | null>>({});
const cover = computed(() => props.policies.find((p) => p.id === form.policy_id));

onMounted(() => {
    const draft = preferences.drafts['claim-register'] as { policy_id: string; loss_date: string; reported_on: string; description: string; policy?: LookupResult | null } | null | undefined;
    if (draft) {
        form.defaults({ policy_id: draft.policy_id, loss_date: draft.loss_date, reported_on: draft.reported_on, description: draft.description });
        form.reset();
        policy.value = draft.policy ?? null;
        toast('Your saved draft is back.');
    }
});

function fail(field: 'policy_id' | 'loss_date' | 'reported_on' | 'description', message: string): void {
    form.setError(field, message);
}

function next(): void {
    form.clearErrors();
    if (step.value === 0 && !form.policy_id) return fail('policy_id', 'Choose the policy the claim is on.');
    if (step.value === 1) {
        if (!form.loss_date) return fail('loss_date', 'Enter the date of loss.');
        if (cover.value && (form.loss_date < cover.value.inception || form.loss_date > cover.value.expiry)) {
            return fail('loss_date', `The loss must fall within the cover, ${formatDate(cover.value.inception)} to ${formatDate(cover.value.expiry)}.`);
        }
        if (!form.reported_on) return fail('reported_on', 'Enter the date the loss was reported.');
        if (form.reported_on < form.loss_date) return fail('reported_on', 'A loss cannot be reported before it happened.');
        if (form.description.trim() === '') return fail('description', 'Describe what happened in a sentence.');
    }
    if (step.value < steps.length - 1) {
        step.value++;
        return;
    }
    form.post('/claims', { onSuccess: () => savePreference(DRAFT, null, 0) });
}

function saveDraft(): void {
    savePreference(DRAFT, { ...form.data(), policy: policy.value }, 0);
    toast('Draft saved. It will be here when you come back.');
}
</script>

<template>
    <AppLayout title="Register a claim">
        <h1 class="mb-4 text-title font-semibold">Register a claim</h1>
        <Stepper :steps="steps" :current="step" @go="step = $event">
            <FormLayout :submit-label="step < steps.length - 1 ? 'Continue' : 'Register claim'" cancel-href="/claims" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" drafts @submit="next" @save-draft="saveDraft">
                <template v-if="step === 0">
                    <Field id="policy_id" label="Policy" :error="form.errors.policy_id" hint="The policy number or the policyholder's name.">
                        <LookupInput v-model="form.policy_id" type="policy" :initial="policy" @selected="policy = $event" />
                    </Field>
                </template>
                <template v-else-if="step === 1">
                    <Field id="loss_date" label="Date of loss" :error="dateErrors.loss_date ?? form.errors.loss_date">
                        <DateInput v-model="form.loss_date" @invalid="dateErrors.loss_date = $event" />
                    </Field>
                    <Field id="reported_on" label="Reported on" :error="dateErrors.reported_on ?? form.errors.reported_on" hint="t for today.">
                        <DateInput v-model="form.reported_on" @invalid="dateErrors.reported_on = $event" />
                    </Field>
                    <Field id="description" label="What happened" :error="form.errors.description">
                        <textarea id="description" v-model="form.description" rows="4" class="rounded-control border border-line-control bg-surface px-2 py-1.5 text-body text-ink" />
                    </Field>
                </template>
                <template v-else>
                    <p class="text-ui text-ink-2">Check the details. Registering opens the claim; reserves and payments follow on the claim page.</p>
                </template>
            </FormLayout>
            <template #summary>
                <dl class="grid gap-2 text-ui">
                    <div><dt class="text-dense text-ink-2">Policy</dt><dd>{{ policy?.label ?? 'Not chosen yet' }}</dd><dd v-if="policy?.detail" class="text-dense text-ink-2">{{ policy.detail }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Date of loss</dt><dd>{{ formatDate(form.loss_date) || '—' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Reported on</dt><dd>{{ formatDate(form.reported_on) || '—' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">What happened</dt><dd class="break-words">{{ form.description || '—' }}</dd></div>
                </dl>
            </template>
        </Stepper>
    </AppLayout>
</template>
