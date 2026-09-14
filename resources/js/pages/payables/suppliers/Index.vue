<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import LookupInput from '@/components/forms/LookupInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** Payables → Suppliers (addendum v2 §B.4): who we pay, on what terms, with which taxes deducted at source and into which bank account. */
interface SupplierRow { id: string; code: string; name: string; category: string; category_label: string; status: string; terms: number; tin: string | null; bin: string | null; bank: string | null; owed: string; next_due: string | null }
defineProps<{ suppliers: SupplierRow[]; categories: { value: string; label: string }[]; canManage: boolean }>();

const active = ref<string | null>(null);
const adding = ref(false);
const form = useForm({ code: '', name: '', category: 'other', payment_terms_days: '30', tin: '', bin: '', vat_registered: false, default_account_id: '', bank_name: '', bank_branch: '', routing_no: '', account_name: '', account_no: '', mobile: '', email: '', address: '' });
const columns: DataColumn<SupplierRow>[] = [
    { id: 'name', header: 'Supplier', value: (s) => s.name, href: (s) => `/payables/suppliers/${s.id}`, width: 240 },
    { id: 'code', header: 'Code', value: (s) => s.code, width: 110, muted: true },
    { id: 'category', header: 'Category', value: (s) => s.category_label, width: 200 },
    { id: 'terms', header: 'Terms (days)', type: 'number', value: (s) => s.terms, width: 110 },
    { id: 'bank', header: 'Paid into', value: (s) => s.bank ?? 'No bank account', width: 200, muted: true },
    { id: 'owed', header: 'Owed', type: 'money', value: (s) => s.owed, total: true },
    { id: 'next_due', header: 'Next due', type: 'date', value: (s) => s.next_due },
    { id: 'status', header: 'Status', type: 'status', value: (s) => s.status },
];
function submit(): void {
    form.post('/payables/suppliers', { onSuccess: () => (adding.value = false) });
}
</script>

<template>
    <AppLayout help="bank" title="Suppliers" fill>
        <QueueView
            id="suppliers"
            v-model:active="active"
            title="Suppliers"
            :columns="columns"
            :rows="suppliers"
            :row-key="(s) => s.id"
            currency="BDT"
            empty-text="No suppliers yet: add the landlord, utilities and garages you pay."
            :action="canManage ? { label: 'Add a supplier' } : null"
            :inspector-title="(s) => s.name"
            :inspector-subtitle="(s) => `${s.code} · ${s.category_label}`"
            @action="adding = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Owed', value: `${formatMoney(row.owed)} BDT`, num: true }, { label: 'Next due', value: formatDate(row.next_due) }, { label: 'TIN', value: row.tin ?? '—' }, { label: 'BIN', value: row.bin ?? '—' }, { label: 'Paid into', value: row.bank ?? 'No bank account' }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div class="mt-4 flex gap-2">
                    <Link :href="`/payables/suppliers/${row.id}`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Open the supplier</Link>
                    <Link :href="`/payables/bills/create?supplier=${row.id}`" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Enter a bill</Link>
                </div>
            </template>
        </QueueView>
        <Drawer v-model:open="adding" title="Add a supplier">
            <FormLayout submit-label="Add supplier" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="submit" @cancel="adding = false">
                <Field id="supplier_name" label="Name" :error="form.errors.name"><TextInput v-model="form.name" placeholder="Gulshan Properties Ltd" /></Field>
                <Field id="supplier_code" label="Code" hint="Short and unique, like SUP-DESCO." :error="form.errors.code"><TextInput v-model="form.code" :maxlength="32" /></Field>
                <Field id="supplier_category" label="Category" hint="Sets VAT and the VAT and income tax deducted at source (placeholder rates, verify)." :error="form.errors.category">
                    <SelectInput id="supplier_category" v-model="form.category" :options="categories" />
                </Field>
                <Field id="supplier_terms" label="Payment terms (days)" :error="form.errors.payment_terms_days"><TextInput v-model="form.payment_terms_days" inputmode="numeric" /></Field>
                <Field id="supplier_tin" label="TIN" optional :error="form.errors.tin"><TextInput v-model="form.tin" :maxlength="32" /></Field>
                <Field id="supplier_bin" label="BIN (VAT registration)" optional :error="form.errors.bin"><TextInput v-model="form.bin" :maxlength="32" /></Field>
                <Field id="supplier_account" label="Default expense account" optional :error="form.errors.default_account_id">
                    <LookupInput id="supplier_account" v-model="form.default_account_id" type="account" placeholder="Code or name, like 5400" />
                </Field>
                <Field id="supplier_bank" label="Bank" optional :error="form.errors.bank_name"><TextInput v-model="form.bank_name" placeholder="Dutch-Bangla Bank" /></Field>
                <Field id="supplier_branch" label="Bank branch" optional :error="form.errors.bank_branch"><TextInput v-model="form.bank_branch" /></Field>
                <Field id="supplier_routing" label="Routing number" hint="The 9-digit BEFTN routing number." optional :error="form.errors.routing_no"><TextInput v-model="form.routing_no" inputmode="numeric" :maxlength="9" /></Field>
                <Field id="supplier_account_name" label="Account name" optional :error="form.errors.account_name"><TextInput v-model="form.account_name" /></Field>
                <Field id="supplier_account_no" label="Account number" hint="Stored encrypted; screens show the last four digits." optional :error="form.errors.account_no"><TextInput v-model="form.account_no" inputmode="numeric" /></Field>
                <Field id="supplier_mobile" label="Mobile" optional :error="form.errors.mobile"><TextInput v-model="form.mobile" placeholder="+8801711000000" inputmode="tel" /></Field>
                <Field id="supplier_email" label="Email" optional :error="form.errors.email"><TextInput v-model="form.email" inputmode="email" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
