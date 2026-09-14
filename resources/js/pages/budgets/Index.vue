<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/** Design addendum v2 §B.8.1: budget versions per fiscal year (draft → submitted → approved → superseded). */
interface BudgetRow { id: string; year: string; code: string; name: string; version: number; status: string; prepared_by: string; approved_by: string | null; total: string }
const props = defineProps<{ budgets: BudgetRow[]; years: { value: string; label: string }[]; defaultYear: string; can: { prepare: boolean } }>();

const active = ref<string | null>(null);
const creating = ref(false);
const form = useForm({ fiscal_year: props.defaultYear, code: 'MAIN', name: 'Operating budget' });
const columns: DataColumn<BudgetRow>[] = [
    { id: 'year', header: 'Fiscal year', value: (r) => r.year, href: (r) => `/budgets/${r.id}`, width: 120 },
    { id: 'name', header: 'Budget', value: (r) => `${r.name} (${r.code})`, width: 260 },
    { id: 'version', header: 'Version', type: 'number', value: (r) => r.version },
    { id: 'total', header: 'Total', type: 'money', value: (r) => r.total },
    { id: 'prepared', header: 'Prepared by', value: (r) => r.prepared_by, muted: true },
    { id: 'approved', header: 'Approved by', value: (r) => r.approved_by, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['draft', 'submitted', 'approved', 'superseded'] },
];
</script>

<template>
    <AppLayout title="Budgets" fill>
        <QueueView
            id="budgets"
            v-model:active="active"
            title="Budgets"
            :columns="columns"
            :rows="budgets"
            :row-key="(r) => r.id"
            currency="BDT"
            empty-text="No budgets yet. Prepare this year's budget by account, branch and month."
            :action="can.prepare ? { label: 'New budget' } : null"
            :inspector-title="(r) => `${r.name} ${r.year}`"
            :primary-label="() => 'Open budget'"
            @action="creating = true"
            @primary="(r) => router.visit(`/budgets/${r.id}`)"
        >
            <template #toolbar>
                <Link href="/budgets/variance" class="ml-3 text-ui text-accent-text hover:underline">Budget variance</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="creating" title="New budget">
            <FormLayout submit-label="Create budget" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/budgets', { onSuccess: () => (creating = false) })" @cancel="creating = false">
                <Field id="fiscal_year" label="Fiscal year" :error="form.errors.fiscal_year"><SelectInput id="fiscal_year" v-model="form.fiscal_year" :options="years" /></Field>
                <Field id="code" label="Code" hint="A new version of an existing code is made from that budget's page." :error="form.errors.code"><TextInput v-model="form.code" /></Field>
                <Field id="name" label="Name" :error="form.errors.name"><TextInput v-model="form.name" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
