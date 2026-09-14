<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/** Reinsurance → Treaties → treaty editor: the treaty's class, year and period, its type and terms, commission, SBC share and the reinsurers that share it. */
interface Option { value: string; label: string }
interface TreatyForm {
    id: string; code: string; name: string; class_code: string; underwriting_year: number; period_from: string; period_to: string; type: string; cession_percent: string; retention: string;
    lines: string; commission_percent: string; sbc_share_percent: string; status: string; participants: { reinsurer_id: string; share_percent: string }[];
}
const props = defineProps<{
    treaty: TreatyForm | null; classes: Option[]; reinsurers: Option[]; currency: string;
    defaults: { underwriting_year: number; period_from: string; period_to: string; sbc_share_percent: string };
}>();

const form = useForm({
    code: props.treaty?.code ?? '', name: props.treaty?.name ?? '', class_code: props.treaty?.class_code ?? props.classes[0]?.value ?? '',
    underwriting_year: String(props.treaty?.underwriting_year ?? props.defaults.underwriting_year), period_from: props.treaty?.period_from ?? props.defaults.period_from,
    period_to: props.treaty?.period_to ?? props.defaults.period_to, type: props.treaty?.type ?? 'quota_share', cession_percent: props.treaty?.cession_percent ?? '',
    retention: props.treaty?.retention ?? '', lines: props.treaty?.lines ?? '', commission_percent: props.treaty?.commission_percent ?? '25.00',
    sbc_share_percent: props.treaty?.sbc_share_percent ?? props.defaults.sbc_share_percent, status: props.treaty?.status ?? 'active',
    participants: props.treaty?.participants ?? [{ reinsurer_id: props.reinsurers[0]?.value ?? '', share_percent: '100.00' }],
});
const errors = computed(() => form.errors as Record<string, string>);
const total = computed(() => form.participants.reduce((sum, p) => sum + (Number.parseFloat(p.share_percent) || 0), 0));
const title = computed(() => (props.treaty ? `Edit treaty ${props.treaty.code}` : 'New treaty'));
function submit(): void {
    if (props.treaty) form.put(`/reinsurance/treaties/${props.treaty.id}`);
    else form.post('/reinsurance/treaties');
}
</script>

<template>
    <AppLayout help="reinsurance" :title="title">
        <Breadcrumb :base="treaty ? [{ label: 'Treaties', href: '/reinsurance/treaties' }, { label: `Treaty ${treaty.code}`, href: `/reinsurance/treaties/${treaty.id}` }] : [{ label: 'Treaties', href: '/reinsurance/treaties' }]" />
        <PageHeader :title="title" description="SBC's compulsory share is ceded first; this treaty applies to the rest. Changes apply to policies ceded from now on; cessions already written stay." />
        <FormLayout :submit-label="treaty ? 'Save treaty' : 'Create treaty'" :cancel-href="treaty ? `/reinsurance/treaties/${treaty.id}` : '/reinsurance/treaties'" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" wide @submit="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field id="code" label="Code" :error="errors.code"><TextInput id="code" v-model="form.code" placeholder="FIRE-SP-2026" /></Field>
                <Field id="name" label="Name" :error="errors.name"><TextInput id="name" v-model="form.name" placeholder="Fire surplus treaty 2026" /></Field>
                <Field id="class_code" label="Product class" :error="errors.class_code"><SelectInput id="class_code" v-model="form.class_code" :options="classes" /></Field>
                <Field id="underwriting_year" label="Underwriting year" :error="errors.underwriting_year"><TextInput id="underwriting_year" v-model="form.underwriting_year" inputmode="numeric" /></Field>
                <Field id="period_from" label="Covers policies starting from" :error="errors.period_from"><DateInput id="period_from" v-model="form.period_from" /></Field>
                <Field id="period_to" label="Until" :error="errors.period_to"><DateInput id="period_to" v-model="form.period_to" /></Field>
                <Field id="type" label="Type" :error="errors.type">
                    <SelectInput id="type" v-model="form.type" :options="[{ value: 'quota_share', label: 'Quota share: a fixed % of every risk' }, { value: 'surplus', label: 'Surplus: lines above our retention' }]" />
                </Field>
                <Field v-if="form.type === 'quota_share'" id="cession_percent" label="Cession %" hint="Share of each risk (after SBC) ceded, like 40.00." :error="errors.cession_percent"><TextInput id="cession_percent" v-model="form.cession_percent" inputmode="decimal" /></Field>
                <template v-else>
                    <Field id="retention" :label="`Retention per risk (${currency})`" hint="One line; 5 crore is 50,000,000.00." :error="errors.retention"><MoneyInput id="retention" v-model="form.retention" /></Field>
                    <Field id="lines" label="Lines" hint="Treaty capacity = lines × retention." :error="errors.lines"><TextInput id="lines" v-model="form.lines" inputmode="numeric" /></Field>
                </template>
                <Field id="commission_percent" label="Reinsurance commission %" hint="On ceded premium." :error="errors.commission_percent"><TextInput id="commission_percent" v-model="form.commission_percent" inputmode="decimal" /></Field>
                <Field id="sbc_share_percent" label="SBC compulsory share %" hint="Placeholder 50%: verify the statutory share." :error="errors.sbc_share_percent"><TextInput id="sbc_share_percent" v-model="form.sbc_share_percent" inputmode="decimal" /></Field>
                <Field id="status" label="Status" :error="errors.status"><SelectInput id="status" v-model="form.status" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]" /></Field>
            </div>
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-ui font-medium">Reinsurers on this treaty <span class="font-normal text-ink-2">· shares total {{ total.toFixed(2) }}%</span></legend>
                <div v-for="(p, i) in form.participants" :key="i" class="grid grid-cols-[1fr_140px_auto] items-end gap-2">
                    <Field :id="`participant_${i}`" label="Reinsurer" :error="errors[`participants.${i}.reinsurer_id`]"><SelectInput :id="`participant_${i}`" v-model="p.reinsurer_id" :options="reinsurers" /></Field>
                    <Field :id="`share_${i}`" label="Share %" :error="errors[`participants.${i}.share_percent`]"><TextInput :id="`share_${i}`" v-model="p.share_percent" inputmode="decimal" /></Field>
                    <button type="button" class="h-8 rounded-control px-2 text-ui text-ink-2 hover:bg-surface-2" :disabled="form.participants.length === 1" @click="form.participants.splice(i, 1)">Remove</button>
                </div>
                <p v-if="errors.participants" class="text-dense text-danger">{{ errors.participants }}</p>
                <button type="button" class="h-8 w-fit rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="form.participants.push({ reinsurer_id: reinsurers[0]?.value ?? '', share_percent: '' })">Add reinsurer</button>
            </fieldset>
        </FormLayout>
    </AppLayout>
</template>
