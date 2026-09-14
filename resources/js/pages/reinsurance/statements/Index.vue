<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** Reinsurance → Reinsurer statements: the quarterly account per reinsurer — premium ceded, commission, claims recoverable and the balance. */
interface StatementRow {
    id: string; number: string; reinsurer: string; quarter: string; period_from: string; period_to: string; opening: string; premium: string; commission: string; claims_recoverable: string;
    closing: string; outstanding_claims_share: string; due_to_reinsurer: boolean; bordereau: string;
}
const props = defineProps<{ statements: StatementRow[]; reinsurers: { value: string; label: string }[]; canPrepare: boolean; currency: string; defaults: { year: number; quarter: number } }>();

const active = ref<string | null>(null);
const drawer = ref(false);
const form = useForm({ reinsurer_id: props.reinsurers[0]?.value ?? '', year: String(props.defaults.year), quarter: String(props.defaults.quarter) });
const errors = computed(() => form.errors as Record<string, string>);
function submit(): void {
    form.post('/reinsurance/statements', { preserveScroll: true, onSuccess: () => (drawer.value = false) });
}
const columns: DataColumn<StatementRow>[] = [
    { id: 'number', header: 'Statement', value: (s) => s.number, width: 150 },
    { id: 'reinsurer', header: 'Reinsurer', value: (s) => s.reinsurer, width: 240 },
    { id: 'quarter', header: 'Quarter', value: (s) => s.quarter, width: 100 },
    { id: 'opening', header: 'Opening', type: 'money', value: (s) => s.opening },
    { id: 'premium', header: 'Premium ceded', type: 'money', value: (s) => s.premium, total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (s) => s.commission, total: true },
    { id: 'recoverable', header: 'Claims recoverable', type: 'money', value: (s) => s.claims_recoverable, total: true },
    { id: 'closing', header: 'Balance', type: 'money', value: (s) => s.closing },
];
</script>

<template>
    <AppLayout title="Reinsurer statements" fill>
        <QueueView
            id="ri-statements"
            v-model:active="active"
            title="Reinsurer statements"
            :columns="columns"
            :rows="statements"
            :row-key="(s) => s.id"
            :currency="currency"
            empty-text="No statements yet. Prepare one for a reinsurer and quarter."
            :action="canPrepare ? { label: 'Prepare statement' } : null"
            :inspector-title="(s) => `${s.number} · ${s.quarter}`"
            :inspector-subtitle="(s) => s.reinsurer"
            @action="drawer = true"
        >
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Period', value: `${formatDate(row.period_from)} to ${formatDate(row.period_to)}` },
                    { label: 'Opening balance', value: `${formatMoney(row.opening)} ${currency}`, num: true },
                    { label: 'Premium ceded', value: `${formatMoney(row.premium)} ${currency}`, num: true },
                    { label: 'Less commission', value: `${formatMoney(row.commission)} ${currency}`, num: true },
                    { label: 'Less claims recoverable', value: `${formatMoney(row.claims_recoverable)} ${currency}`, num: true },
                    { label: 'Closing balance', value: `${formatMoney(row.closing)} ${currency} ${row.due_to_reinsurer ? 'due to the reinsurer' : 'due from the reinsurer'}`, num: true },
                    { label: 'Share of outstanding claims', value: `${formatMoney(row.outstanding_claims_share)} ${currency}`, num: true },
                ]" />
                <div class="mt-4 flex flex-col gap-1 text-ui">
                    <Link :href="row.bordereau" class="text-accent-text hover:underline">Premium bordereau for the quarter</Link>
                    <Link :href="row.bordereau.replace('ri-premium-bordereau', 'ri-claims-bordereau')" class="text-accent-text hover:underline">Claims bordereau for the quarter</Link>
                </div>
            </template>
        </QueueView>

        <Drawer v-model:open="drawer" title="Prepare a reinsurer statement">
            <p class="mb-4 text-ui text-ink-2">Preparing a quarter again refreshes its figures; the statement number stays.</p>
            <FormLayout submit-label="Prepare statement" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="drawer = false">
                <Field id="reinsurer_id" label="Reinsurer" :error="errors.reinsurer_id"><SelectInput id="reinsurer_id" v-model="form.reinsurer_id" :options="reinsurers" /></Field>
                <Field id="year" label="Year" :error="errors.year"><TextInput id="year" v-model="form.year" inputmode="numeric" /></Field>
                <Field id="quarter" label="Quarter" :error="errors.quarter">
                    <SelectInput id="quarter" v-model="form.quarter" :options="[{ value: '1', label: 'Q1 (January–March)' }, { value: '2', label: 'Q2 (April–June)' }, { value: '3', label: 'Q3 (July–September)' }, { value: '4', label: 'Q4 (October–December)' }]" />
                </Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
