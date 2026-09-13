<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/** Compensation schemes (Distribution design note §0): the pay mode per product, with its compliance profile and rules. */
interface SchemeRow { id: string; code: string; name: string; mode: string; effective_from: string; effective_to: string | null; rules: number; products: number }
defineProps<{ schemes: SchemeRow[]; can: { manage: boolean } }>();

const active = ref<string | null>(null);
const creating = ref(false);
const modeWords: Record<string, string> = { commission: 'Commission', salary_incentive: 'Salary and incentives', hybrid: 'Hybrid', none: 'No pay' };
const form = useForm({ code: '', name: '', mode: 'commission', effective_from: '' });
const columns: DataColumn<SchemeRow>[] = [
    { id: 'code', header: 'Code', value: (s) => s.code, href: (s) => `/distribution/schemes/${s.id}`, width: 140 },
    { id: 'name', header: 'Name', value: (s) => s.name, width: 240 },
    { id: 'mode', header: 'Pays by', value: (s) => modeWords[s.mode] ?? s.mode, width: 160, filterOptions: Object.values(modeWords) },
    { id: 'products', header: 'Products', type: 'number', value: (s) => s.products, width: 90 },
    { id: 'rules', header: 'Rules', type: 'number', value: (s) => s.rules, width: 80 },
    { id: 'from', header: 'From', type: 'date', value: (s) => s.effective_from },
    { id: 'to', header: 'Until', type: 'date', value: (s) => s.effective_to },
];
</script>

<template>
    <AppLayout title="Compensation schemes" fill>
        <QueueView
            id="distribution-schemes"
            v-model:active="active"
            title="Compensation schemes"
            :columns="columns"
            :rows="schemes"
            :row-key="(s) => s.id"
            empty-text="No schemes yet: create one for each way producers are paid."
            :action="can.manage ? { label: 'New scheme' } : null"
            :inspector-title="(s) => s.code"
            :inspector-subtitle="(s) => s.name"
            @action="creating = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Pays by', value: modeWords[row.mode] }, { label: 'Products using it', value: row.products, num: true }, { label: 'Rules', value: row.rules, num: true }, { label: 'In force', value: `${formatDate(row.effective_from)} ${row.effective_to ? `to ${formatDate(row.effective_to)}` : 'onwards'}` }]" />
                <Link :href="`/distribution/schemes/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the scheme</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="creating" title="New compensation scheme">
            <FormLayout submit-label="Create scheme" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/distribution/schemes')" @cancel="creating = false">
                <Field id="scheme_code" label="Code" :error="form.errors.code"><TextInput v-model="form.code" /></Field>
                <Field id="scheme_name" label="Name" :error="form.errors.name"><TextInput v-model="form.name" /></Field>
                <Field id="scheme_mode" label="Pays by" hint="Non-life commission stays off until the compliance profile allows it." :error="form.errors.mode">
                    <SelectInput id="scheme_mode" v-model="form.mode" :options="Object.entries(modeWords).map(([value, label]) => ({ value, label }))" />
                </Field>
                <Field id="scheme_from" label="In force from" :error="form.errors.effective_from"><DateInput v-model="form.effective_from" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
