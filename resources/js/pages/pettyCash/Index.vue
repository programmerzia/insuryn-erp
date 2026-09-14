<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { useMoneyForm } from '@/lib/moneyForm';

/** Design addendum v2 §B.6: petty cash floats per branch, with cash on hand and what is waiting to be replenished. */
interface FloatRow { id: string; code: string; name: string; branch: string; custodian: string; limit: string; on_hand: string; to_replenish: string; pending: boolean; status: string }
type Option = { id: string; label: string };
const props = defineProps<{ floats: FloatRow[]; branches: Option[]; users: Option[]; accounts: Option[]; bankAccounts: Option[]; can: { create: boolean } }>();

const active = ref<string | null>(null);
const creating = ref(false);
const today = useBusinessToday();
const create = useMoneyForm(() => '/petty-cash', { branch_id: props.branches[0]?.id ?? '', code: '', name: '', custodian_user_id: '', limit: '', gl_account_id: props.accounts.find((a) => a.label.includes('Petty'))?.id ?? '',
    bank_account_id: props.bankAccounts[0]?.id ?? '', issued_on: today }, () => (creating.value = false));
const columns: DataColumn<FloatRow>[] = [
    { id: 'code', header: 'Float', value: (r) => r.code, href: (r) => `/petty-cash/${r.id}`, width: 110 },
    { id: 'name', header: 'Name', value: (r) => r.name, width: 220 },
    { id: 'branch', header: 'Branch', value: (r) => r.branch, width: 80 },
    { id: 'custodian', header: 'Custodian', value: (r) => r.custodian, muted: true },
    { id: 'limit', header: 'Float limit', type: 'money', value: (r) => r.limit },
    { id: 'on_hand', header: 'Cash on hand', type: 'money', value: (r) => r.on_hand, total: true },
    { id: 'to_replenish', header: 'Spent, to replenish', type: 'money', value: (r) => r.to_replenish, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => (r.pending ? 'pending_approval' : r.status) },
];
</script>

<template>
    <AppLayout title="Petty cash" fill>
        <QueueView
            id="petty-cash"
            v-model:active="active"
            title="Petty cash"
            :columns="columns"
            :rows="floats"
            :row-key="(r) => r.id"
            currency="BDT"
            empty-text="No petty cash floats yet. Give each branch a float for small expenses."
            :action="can.create ? { label: 'New float' } : null"
            :inspector-title="(r) => `${r.code} ${r.name}`"
            :primary-label="() => 'Open float'"
            @action="creating = true"
            @primary="(r) => router.visit(`/petty-cash/${r.id}`)"
        >
            <template #toolbar><Link href="/petty-cash/book" class="ml-3 text-ui text-accent-text hover:underline">Petty cash book</Link></template>
        </QueueView>
        <Drawer v-model:open="creating" title="New petty cash float">
            <FormLayout submit-label="Review the journal" :dirty="create.form.isDirty" :processing="create.form.processing" :error="(create.form.errors as Record<string, string>).form" @submit="create.review" @cancel="creating = false">
                <Field id="branch_id" label="Branch" :error="create.form.errors.branch_id"><SelectInput id="branch_id" v-model="create.form.branch_id" :options="branches.map((b) => ({ value: b.id, label: b.label }))" /></Field>
                <Field id="code" label="Code" :error="create.form.errors.code"><TextInput v-model="create.form.code" placeholder="PC-HO" /></Field>
                <Field id="name" label="Name" :error="create.form.errors.name"><TextInput v-model="create.form.name" /></Field>
                <Field id="custodian_user_id" label="Custodian" :error="create.form.errors.custodian_user_id"><SelectInput id="custodian_user_id" v-model="create.form.custodian_user_id" placeholder="Who holds the cash" :options="users.map((u) => ({ value: u.id, label: u.label }))" /></Field>
                <Field id="limit" label="Float limit (BDT)" :error="create.form.errors.limit"><MoneyInput v-model="create.form.limit" /></Field>
                <Field id="gl_account_id" label="Petty cash account" :error="create.form.errors.gl_account_id"><SelectInput id="gl_account_id" v-model="create.form.gl_account_id" :options="accounts.map((a) => ({ value: a.id, label: a.label }))" /></Field>
                <Field id="bank_account_id" label="Drawn from" :error="create.form.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="create.form.bank_account_id" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" /></Field>
                <Field id="issued_on" label="Issued on" :error="create.form.errors.issued_on"><DateInput v-model="create.form.issued_on" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="create.previewOpen.value" :result="create.preview.value" title="Issue this float?" confirm-label="Issue the float" currency="BDT" :processing="create.form.processing" @confirm="create.post" />
    </AppLayout>
</template>
