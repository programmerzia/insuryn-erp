<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

/** Design addendum v2 §B.7 asset classes: method, useful life or yearly rate, residual, capitalisation threshold and the GL accounts each class posts to. */
interface ClassRow { id: string; code: string; name: string; method: string; useful_life_months: string; rate: string; residual: string; threshold: string; cost_account_id: string;
    accumulated_account_id: string; expense_account_id: string; disposal_account_id: string; accounts: string; assets: number }
const props = defineProps<{ classes: ClassRow[]; accounts: { id: string; label: string; type: string }[]; can: { manage: boolean } }>();

const active = ref<string | null>(null);
const editing = ref<ClassRow | null>(null);
const open = ref(false);
const blank = { code: '', name: '', method: 'straight_line', useful_life_months: '60', rate: '', residual: '0', threshold: '10,000.00', cost_account_id: '', accumulated_account_id: '', expense_account_id: '', disposal_account_id: '' };
const form = useForm({ ...blank });
const columns: DataColumn<ClassRow>[] = [
    { id: 'code', header: 'Code', value: (r) => r.code, width: 90 },
    { id: 'name', header: 'Class', value: (r) => r.name, width: 220 },
    { id: 'method', header: 'Method', value: (r) => (r.method === 'straight_line' ? `Straight line, ${r.useful_life_months} months` : `Reducing balance, ${r.rate}% a year`), width: 220 },
    { id: 'residual', header: 'Residual %', type: 'number', value: (r) => r.residual },
    { id: 'threshold', header: 'Capitalise from', type: 'money', value: (r) => r.threshold },
    { id: 'accounts', header: 'Cost / accumulated / expense', value: (r) => r.accounts, muted: true, width: 220 },
    { id: 'assets', header: 'Assets', type: 'number', value: (r) => r.assets },
];
const options = (types: string[]) => props.accounts.filter((a) => types.includes(a.type)).map((a) => ({ value: a.id, label: a.label }));

function edit(row: ClassRow | null): void {
    editing.value = row;
    Object.assign(form, row ? { code: row.code, name: row.name, method: row.method, useful_life_months: row.useful_life_months, rate: row.rate, residual: row.residual, threshold: row.threshold,
        cost_account_id: row.cost_account_id, accumulated_account_id: row.accumulated_account_id, expense_account_id: row.expense_account_id, disposal_account_id: row.disposal_account_id } : blank);
    open.value = true;
}
function save(): void {
    const done = { onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/fixed-assets/classes/${editing.value.id}`, done);
    else form.post('/fixed-assets/classes', done);
}
</script>

<template>
    <AppLayout help="assets" title="Asset classes" fill>
        <QueueView
            id="asset-classes"
            v-model:active="active"
            title="Asset classes"
            :columns="columns"
            :rows="classes"
            :row-key="(r) => r.id"
            currency="BDT"
            empty-text="No asset classes yet. Add the usual classes (furniture, IT equipment, vehicles, office equipment, leasehold improvements) and check their rates."
            :action="can.manage ? { label: 'New class' } : null"
            :hint="can.manage ? null : 'Only the accountant can add asset classes.'"
            :inspector-title="(r) => r.name"
            :primary-label="() => (can.manage ? 'Edit class' : undefined)"
            @action="edit(null)"
            @primary="edit"
        >
            <template #toolbar>
                <button v-if="can.manage" type="button" class="ml-2 h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="router.post('/fixed-assets/classes/defaults')">Add the default classes</button>
                <Link href="/fixed-assets" class="ml-3 text-ui text-accent-text hover:underline">Fixed assets</Link>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Method', value: row.method === 'straight_line' ? `Straight line over ${row.useful_life_months} months` : `Reducing balance at ${row.rate}% a year` }, { label: 'Residual value', value: `${row.residual}% of cost` }, { label: 'Capitalise from', value: `${formatMoney(row.threshold)} BDT`, num: true }, { label: 'Cost / accumulated / expense accounts', value: row.accounts }, { label: 'Assets in the class', value: String(row.assets) }]" />
            </template>
        </QueueView>
        <Drawer v-model:open="open" :title="editing ? `Edit ${editing.name}` : 'New asset class'" width="w-[520px]">
            <FormLayout submit-label="Save class" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="save" @cancel="open = false">
                <p v-if="editing?.assets" class="text-ui text-ink-2">Changes apply to assets capitalised from now on; the {{ editing.assets }} asset(s) in this class keep their method and life.</p>
                <Field id="code" label="Code" :error="form.errors.code"><TextInput v-model="form.code" /></Field>
                <Field id="name" label="Name" :error="form.errors.name"><TextInput v-model="form.name" /></Field>
                <Field id="method" label="Depreciation method" :error="form.errors.method">
                    <SelectInput id="method" v-model="form.method" :options="[{ value: 'straight_line', label: 'Straight line' }, { value: 'reducing_balance', label: 'Reducing balance' }]" />
                </Field>
                <Field v-if="form.method === 'straight_line'" id="useful_life_months" label="Useful life (months)" :error="form.errors.useful_life_months"><TextInput v-model="form.useful_life_months" inputmode="numeric" /></Field>
                <Field v-else id="rate" label="Yearly rate (%)" :error="form.errors.rate"><TextInput v-model="form.rate" inputmode="decimal" /></Field>
                <Field id="residual" label="Residual value (% of cost)" :error="form.errors.residual"><TextInput v-model="form.residual" inputmode="decimal" /></Field>
                <Field id="threshold" label="Capitalise from (BDT)" hint="Purchases below this are expensed." :error="form.errors.threshold"><MoneyInput v-model="form.threshold" /></Field>
                <Field id="cost_account_id" label="Cost account" :error="form.errors.cost_account_id"><SelectInput id="cost_account_id" v-model="form.cost_account_id" placeholder="Choose an account" :options="options(['asset'])" /></Field>
                <Field id="accumulated_account_id" label="Accumulated depreciation account" :error="form.errors.accumulated_account_id"><SelectInput id="accumulated_account_id" v-model="form.accumulated_account_id" placeholder="Choose an account" :options="options(['asset'])" /></Field>
                <Field id="expense_account_id" label="Depreciation expense account" :error="form.errors.expense_account_id"><SelectInput id="expense_account_id" v-model="form.expense_account_id" placeholder="Choose an account" :options="options(['expense'])" /></Field>
                <Field id="disposal_account_id" label="Gain or loss on disposal account" optional :error="form.errors.disposal_account_id"><SelectInput id="disposal_account_id" v-model="form.disposal_account_id" placeholder="The mapped account" :options="options(['income', 'expense'])" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
