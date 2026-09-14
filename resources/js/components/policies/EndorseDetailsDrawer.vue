<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import Drawer from '@/components/ui/Drawer.vue';

/**
 * Gap fixes W7 (GA-25): an endorsement that changes no premium — the insured's name, the address, the mortgagee or the contact details on the policy.
 * It takes the next endorsement number and prints like any endorsement; nothing is posted, so there is no journal preview.
 */
export interface InsuredDetails {
    insured_name: string;
    address: string | null;
    mortgagee: string | null;
    mobile: string | null;
    email: string | null;
}
const props = defineProps<{ policyId: string; title: string; today: string; details: InsuredDetails }>();
const open = defineModel<boolean>('open', { default: false });

const kinds = [
    { value: 'name', label: 'Name of the insured' },
    { value: 'address', label: 'Address' },
    { value: 'mortgagee', label: 'Mortgagee' },
    { value: 'contact', label: 'Contact details' },
];
const initial = () => ({ kind: 'address', effective_date: props.today, reason: '', insured_name: props.details.insured_name, address: props.details.address ?? '',
    mortgagee: props.details.mortgagee ?? '', mobile: props.details.mobile ?? '', email: props.details.email ?? '' });
const form = useForm(initial());
watch(open, (isOpen) => {
    if (isOpen) form.defaults(initial()).reset();
});
const kindLabel = computed(() => kinds.find((k) => k.value === form.kind)?.label ?? '');

function submit(): void {
    form.post(`/policies/${props.policyId}/endorse-details`, { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Drawer v-model:open="open" :title="`Change details of ${title}`">
        <FormLayout submit-label="Record endorsement" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="submit" @cancel="open = false">
            <p class="text-ui text-ink-2">The premium does not change and nothing is posted. The endorsement gets the next number and can be printed from Documents.</p>
            <Field id="details_kind" label="What changes" :error="form.errors.kind"><SelectInput v-model="form.kind" :options="kinds" /></Field>
            <Field id="details_effective_date" label="Effective from" :error="form.errors.effective_date"><DateInput v-model="form.effective_date" /></Field>
            <Field v-if="form.kind === 'name'" id="details_insured_name" label="Name as it should read on the policy" :error="form.errors.insured_name"><TextInput v-model="form.insured_name" /></Field>
            <Field v-if="form.kind === 'address'" id="details_address" label="New address" :error="form.errors.address"><TextInput v-model="form.address" /></Field>
            <Field v-if="form.kind === 'mortgagee'" id="details_mortgagee" label="Mortgagee" hint="The bank or lender the vehicle or property is mortgaged to. Leave it empty when the loan is repaid." optional :error="form.errors.mortgagee">
                <TextInput v-model="form.mortgagee" />
            </Field>
            <template v-if="form.kind === 'contact'">
                <Field id="details_mobile" label="Mobile" hint="Like 01712 345678." optional :error="form.errors.mobile"><TextInput v-model="form.mobile" inputmode="tel" /></Field>
                <Field id="details_email" label="Email" optional :error="form.errors.email"><TextInput v-model="form.email" inputmode="email" /></Field>
            </template>
            <Field id="details_reason" :label="`Why the ${kindLabel.toLowerCase()} changes`" :error="form.errors.reason"><TextInput v-model="form.reason" /></Field>
        </FormLayout>
    </Drawer>
</template>
