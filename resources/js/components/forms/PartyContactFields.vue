<script setup lang="ts">
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import TextInput from '@/components/forms/TextInput.vue';
import { identityLabel } from '@/lib/lookupCreate';

/**
 * GA-17: a party's contact details on the Parties forms — mobile (required for a person where the tenant requires it, A-193), email, postal address, NID or BRN,
 * a person's date of birth, an organisation's contact person. The form object is edited in place (an Inertia useForm).
 */
defineProps<{
    form: { kind: string; mobile: string; email: string; address: string; identity_no: string; date_of_birth: string; contact_person: string };
    errors: Partial<Record<string, string>>;
    mobileRequired: boolean;
}>();
</script>

<template>
    <Field id="mobile" label="Mobile" :optional="!(mobileRequired && form.kind === 'individual')" hint="Renewal and claim messages go to it. 01712 345678, or +country code abroad." :error="errors.mobile">
        <TextInput id="mobile" v-model="form.mobile" inputmode="tel" placeholder="01712 345678" />
    </Field>
    <Field id="email" label="Email" optional :error="errors.email"><TextInput id="email" v-model="form.email" inputmode="email" /></Field>
    <Field id="identity_no" :label="identityLabel(form.kind)" optional :error="errors.identity_no"><TextInput id="identity_no" v-model="form.identity_no" /></Field>
    <Field id="address" label="Address" optional hint="Printed on the policy schedule." :error="errors.address">
        <textarea id="address" v-model="form.address" rows="3" class="w-full rounded-control border border-line-control bg-surface px-2 py-1.5 text-body text-ink" />
    </Field>
    <Field v-if="form.kind === 'individual'" id="date_of_birth" label="Date of birth" optional :error="errors.date_of_birth"><DateInput id="date_of_birth" v-model="form.date_of_birth" /></Field>
    <Field v-else id="contact_person" label="Contact person" optional :error="errors.contact_person"><TextInput id="contact_person" v-model="form.contact_person" /></Field>
</template>
