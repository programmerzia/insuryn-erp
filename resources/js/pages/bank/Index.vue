<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';

interface Account { id: string; bank_name: string; account_no_masked: string; currency: string; status: string; gl_code: string; gl_name: string; unmatched_lines: number }
const props = defineProps<{ entity: { currency: string }; accounts: Account[]; glAccounts: { id: string; code: string; name: string }[] }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const adding = ref(false);
const form = useForm({ gl_account_id: '', bank_name: '', account_no_masked: '', currency: props.entity.currency });
const columns: DataColumn<Account>[] = [
    { id: 'bank', header: 'Bank account', value: (a) => `${a.bank_name} ${a.account_no_masked}`, href: (a) => `/bank/${a.id}`, width: 220 },
    { id: 'ledger', header: 'Ledger account', value: (a) => `${a.gl_code} ${a.gl_name}`, width: 220, muted: true },
    { id: 'currency', header: 'Currency', value: (a) => a.currency, width: 90 },
    { id: 'unmatched', header: 'Unmatched lines', type: 'number', value: (a) => a.unmatched_lines, width: 130 },
    { id: 'status', header: 'Status', type: 'status', value: (a) => a.status },
];
</script>

<template>
    <AppLayout help="bank" title="Bank" fill>
        <QueueView
            id="bank-accounts"
            v-model:active="active"
            title="Bank accounts"
            :columns="columns"
            :rows="accounts"
            :row-key="(a) => a.id"
            empty-text="No bank accounts yet. Add one to import statements."
            :action="can('bank.manage_accounts') ? { label: 'Add a bank account' } : null"
            :inspector-title="(a) => `${a.bank_name} ${a.account_no_masked}`"
            :inspector-subtitle="(a) => `${a.gl_code} ${a.gl_name}`"
            @action="adding = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Unmatched lines', value: row.unmatched_lines }, { label: 'Currency', value: row.currency }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/bank/${row.id}`" class="mt-4 inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Match statement lines</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="adding" title="Add a bank account">
            <FormLayout submit-label="Add bank account" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/bank', { onSuccess: () => (adding = false) })" @cancel="adding = false">
                <Field id="gl_account_id" label="Ledger account" :error="form.errors.gl_account_id"><SelectInput id="gl_account_id" v-model="form.gl_account_id" placeholder="Choose an asset account" :options="glAccounts.map((a) => ({ value: a.id, label: `${a.code} ${a.name}` }))" /></Field>
                <Field id="bank_name" label="Bank" :error="form.errors.bank_name"><TextInput v-model="form.bank_name" /></Field>
                <Field id="account_no_masked" label="Account number" hint="Show only the last four digits, like ****4471." :error="form.errors.account_no_masked"><TextInput v-model="form.account_no_masked" placeholder="****4471" /></Field>
                <Field id="currency" label="Currency" :error="form.errors.currency"><TextInput v-model="form.currency" :maxlength="3" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
