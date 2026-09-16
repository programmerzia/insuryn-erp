<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';
import { type Paginated, serverPage } from '@/lib/paging';
const currency = useEntityCurrency();

/** Design addendum v2 §B.7: the fixed asset register queue; capitalise an asset (journal preview), then attach its invoice on the asset page. */
interface AssetRow { id: string; number: string; description: string; class: string; branch: string; location: string | null; custodian: string | null; acquired_on: string; cost: string; accumulated: string | null; nbv: string; status: string }
type Option = { id: string; label: string };
const props = defineProps<{
    assets: Paginated<AssetRow>;
    defaultBranchId: string;
    classes: (Option & { threshold: string })[];
    branches: Option[];
    bankAccounts: Option[];
    can: { manage: boolean; depreciate: boolean };
}>();

const active = ref<string | null>(null);
const acquiring = ref(false);
const today = useBusinessToday();
const acquire = useMoneyForm(() => '/fixed-assets', { class_id: '', branch_id: props.defaultBranchId, description: '', serial_no: '', location: '', custodian: '', supplier: '', invoice_ref: '',
    acquired_on: today, cost: '', paid_via: 'payable', bank_account_id: props.bankAccounts[0]?.id ?? '' }, () => (acquiring.value = false));
const columns: DataColumn<AssetRow>[] = [
    { id: 'number', header: 'Asset', value: (r) => r.number, href: (r) => `/fixed-assets/${r.id}`, width: 140 },
    { id: 'description', header: 'Description', value: (r) => r.description, width: 240 },
    { id: 'class', header: 'Class', value: (r) => r.class, width: 180 },
    { id: 'branch', header: 'Branch', value: (r) => r.branch, width: 80 },
    { id: 'custodian', header: 'Custodian', value: (r) => r.custodian, muted: true },
    { id: 'acquired', header: 'Acquired', type: 'date', value: (r) => r.acquired_on },
    { id: 'cost', header: 'Cost', type: 'money', value: (r) => r.cost, total: true },
    { id: 'nbv', header: 'Net book value', type: 'money', value: (r) => r.nbv, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['in_service', 'fully_depreciated', 'disposed'] },
];
const threshold = (classId: string) => props.classes.find((c) => c.id === classId)?.threshold;
</script>

<template>
    <AppLayout help="assets" title="Fixed assets" fill>
        <QueueView
            id="fixed-assets"
            v-model:active="active"
            title="Fixed assets"
            :columns="columns"
            :rows="assets.data"
            :page="serverPage(assets)"
            :row-key="(r) => r.id"
            :currency="currency"
            empty-text="No fixed assets yet. Capitalise the company's furniture, computers and vehicles here."
            :action="can.manage && classes.length ? { label: 'Capitalise an asset' } : null"
            :hint="can.manage ? null : 'Only the accountant can capitalise an asset.'"
            :empty-action="classes.length ? null : { label: 'Set up asset classes', href: '/fixed-assets/classes' }"
            :inspector-title="(r) => r.number"
            :inspector-subtitle="(r) => r.description"
            :primary-label="() => 'Open the asset'"
            @action="acquiring = true"
            @primary="(r) => router.visit(`/fixed-assets/${r.id}`)"
        >
            <template #toolbar>
                <nav class="ml-3 flex items-center gap-3 text-ui">
                    <Link href="/fixed-assets/depreciation" class="text-accent-text hover:underline">Monthly depreciation</Link>
                    <Link href="/fixed-assets/register" class="text-accent-text hover:underline">Register report</Link>
                    <Link href="/fixed-assets/classes" class="text-accent-text hover:underline">Asset classes</Link>
                </nav>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Class', value: row.class }, { label: 'Branch', value: row.branch }, { label: 'Location', value: row.location }, { label: 'Custodian', value: row.custodian },
                    { label: 'Acquired', value: formatDate(row.acquired_on) }, { label: 'Cost', value: `${formatMoney(row.cost, currency)}`, num: true },
                    { label: 'Accumulated depreciation', value: row.accumulated ? `${formatMoney(row.accumulated, currency)}` : '—', num: true }, { label: 'Net book value', value: `${formatMoney(row.nbv, currency)}`, num: true }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
            </template>
        </QueueView>
        <Drawer v-model:open="acquiring" title="Capitalise an asset" width="w-[520px]">
            <FormLayout submit-label="Review the journal" :dirty="acquire.form.isDirty" :processing="acquire.form.processing" :error="(acquire.form.errors as Record<string, string>).form" @submit="acquire.review" @cancel="acquiring = false">
                <Field id="class_id" label="Asset class" :hint="acquire.form.class_id ? `Capitalised from ${threshold(acquire.form.class_id)} BDT; below that it is an expense.` : undefined" :error="acquire.form.errors.class_id">
                    <SelectInput id="class_id" v-model="acquire.form.class_id" placeholder="Choose a class" :options="classes.map((c) => ({ value: c.id, label: c.label }))" />
                </Field>
                <Field id="description" label="Description" :error="acquire.form.errors.description"><TextInput v-model="acquire.form.description" placeholder="Dell Latitude 5440 laptop" /></Field>
                <Field id="branch_id" label="Branch" :error="acquire.form.errors.branch_id"><SelectInput id="branch_id" v-model="acquire.form.branch_id" :options="branches.map((b) => ({ value: b.id, label: b.label }))" /></Field>
                <Field id="location" label="Location" optional :error="acquire.form.errors.location"><TextInput v-model="acquire.form.location" placeholder="3rd floor, accounts" /></Field>
                <Field id="custodian" label="Custodian" optional :error="acquire.form.errors.custodian"><TextInput v-model="acquire.form.custodian" /></Field>
                <Field id="serial_no" label="Serial number" optional :error="acquire.form.errors.serial_no"><TextInput v-model="acquire.form.serial_no" /></Field>
                <Field id="supplier" label="Supplier" optional :error="acquire.form.errors.supplier"><TextInput v-model="acquire.form.supplier" /></Field>
                <Field id="invoice_ref" label="Supplier invoice" optional :error="acquire.form.errors.invoice_ref"><TextInput v-model="acquire.form.invoice_ref" /></Field>
                <Field id="acquired_on" label="Acquired on" :error="acquire.form.errors.acquired_on"><DateInput v-model="acquire.form.acquired_on" /></Field>
                <Field id="cost" label="Cost ({{ currency }})" :error="acquire.form.errors.cost"><MoneyInput v-model="acquire.form.cost" /></Field>
                <Field id="paid_via" label="Paid" :error="acquire.form.errors.paid_via">
                    <SelectInput id="paid_via" v-model="acquire.form.paid_via" :options="[{ value: 'payable', label: 'On credit (owed to the supplier)' }, { value: 'bank', label: 'From a bank account' }]" />
                </Field>
                <Field v-if="acquire.form.paid_via === 'bank'" id="bank_account_id" label="Bank account" :error="acquire.form.errors.bank_account_id">
                    <SelectInput id="bank_account_id" v-model="acquire.form.bank_account_id" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" />
                </Field>
                <p class="text-ui text-ink-2">After capitalising, attach the supplier's invoice on the asset's Documents tab.</p>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="acquire.previewOpen.value" :result="acquire.preview.value" title="Capitalise this asset?" confirm-label="Capitalise" :currency="currency" :processing="acquire.form.processing" @confirm="acquire.post" />
    </AppLayout>
</template>
