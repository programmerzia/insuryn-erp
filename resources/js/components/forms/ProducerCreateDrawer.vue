<script setup lang="ts">
import { ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import type { LookupResult } from '@/components/forms/LookupInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import Drawer from '@/components/ui/Drawer.vue';
import { HttpError, requestJson } from '@/lib/http';
import { LICENCE_CLASSES, PRODUCER_TYPES, type ProducerDraft, producerDraft } from '@/lib/producerCreate';

/**
 * Flow fix X9: a new producer from the producer lookup without leaving the quote — name, type, a suggested code, the quote's branch and the licence a producer
 * writing new business needs (Distribution design note §2, §3). POST /lookup/producer creates the party, the producer and the licence together.
 */
const props = defineProps<{ name: string; branchId: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ created: [result: LookupResult] }>();

const draft = ref<ProducerDraft>(producerDraft('', '', ''));
const errors = ref<Record<string, string>>({});
const saving = ref(false);
/** The server's today: a licence is not issued later and must still be valid (POST /lookup/producer checks both). */
const today = ref<string | null>(null);

async function suggest(type: string): Promise<void> {
    try {
        const answer = await requestJson<{ code: string; today: string }>('GET', `/lookup/producer/new?type=${encodeURIComponent(type)}`);
        today.value = answer.today;
        if (draft.value.issued_on === '') draft.value = { ...producerDraft(draft.value.name, draft.value.branch_id, answer.today), producer_type: draft.value.producer_type };
        draft.value.code = answer.code;
    } catch {
        // The code stays for the user to type.
    }
}

watch(open, (isOpen) => {
    if (!isOpen) return;
    draft.value = producerDraft(props.name, props.branchId, '');
    errors.value = {};
    void suggest('agent');
});
watch(() => draft.value.producer_type, (type, previous) => {
    if (open.value && previous !== undefined && type !== previous) void suggest(type);
});

async function create(): Promise<void> {
    saving.value = true;
    try {
        const response = await requestJson<{ result: LookupResult }>('POST', '/lookup/producer', draft.value);
        errors.value = {};
        open.value = false;
        emit('created', response.result);
    } catch (error) {
        const body = error instanceof HttpError ? (error.body as { errors?: Record<string, string[]>; message?: string }) : null;
        errors.value = Object.fromEntries(Object.entries(body?.errors ?? { form: [body?.message ?? 'The producer was not created. Try again.'] }).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : String(v)]));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Drawer v-model:open="open" title="New producer">
        <form class="grid gap-4" novalidate @submit.prevent="create">
            <p v-if="errors.form" class="border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ errors.form }}</p>
            <Field id="new-producer-name" label="Name" :error="errors.name"><TextInput id="new-producer-name" v-model="draft.name" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field id="new-producer-type" label="Producer type" :error="errors.producer_type"><SelectInput id="new-producer-type" v-model="draft.producer_type" :options="PRODUCER_TYPES" /></Field>
                <Field id="new-producer-code" label="Code" hint="Suggested; change it if you use another." :error="errors.code"><TextInput id="new-producer-code" v-model="draft.code" :maxlength="32" /></Field>
            </div>
            <p class="text-ui text-ink-2">The producer joins the quote's branch today. A producer writing new business needs a valid licence for the class.</p>
            <div class="grid grid-cols-2 gap-3">
                <Field id="new-producer-licence" label="Licence number" :error="errors.licence_no"><TextInput id="new-producer-licence" v-model="draft.licence_no" :maxlength="64" /></Field>
                <Field id="new-producer-class" label="Licensed for" :error="errors.licence_class"><SelectInput id="new-producer-class" v-model="draft.licence_class" :options="LICENCE_CLASSES" /></Field>
                <Field id="new-producer-issued" label="Issued on" :error="errors.issued_on"><DateInput id="new-producer-issued" v-model="draft.issued_on" :max="today" /></Field>
                <Field id="new-producer-expires" label="Expires on" :error="errors.expires_on"><DateInput id="new-producer-expires" v-model="draft.expires_on" :min="today && draft.issued_on > today ? draft.issued_on : today" /></Field>
            </div>
            <p v-if="errors.branch_id" class="text-ui text-danger" role="alert">{{ errors.branch_id }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="open = false">Cancel</button>
                <button type="submit" :disabled="saving" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50">Create producer</button>
            </div>
        </form>
    </Drawer>
</template>
