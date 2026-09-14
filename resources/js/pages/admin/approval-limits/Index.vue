<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { formatMinor } from '@/lib/money';

/**
 * Fix F3 (design §7.3 "approval policies are data"): who approves claim payments, their release, manual journals and reversals above which amount,
 * step by step, and from when. A policy in force is never rewritten: changing it ends it the day the change starts.
 */
interface Step { role: string | null; role_name: string | null; permission: string }
interface PolicyRow {
    id: string; object_type: string; label: string; min_amount_minor: number | null; max_amount_minor: number | null; amount: string;
    steps: Step[]; approvers: string; effective_from: string; effective_to: string | null; status: string; kinds: string[] | null;
}
const props = defineProps<{ policies: PolicyRow[]; objectTypes: { value: string; label: string }[]; roles: { code: string; name: string }[]; currency: string; today: string }>();

const active = ref<string | null>(null);
const editing = ref<PolicyRow | null>(null);
const drawerOpen = ref(false);
const ending = ref<PolicyRow | null>(null);
const endOpen = ref(false);

const major = (minor: number | null) => (minor === null || minor === 0 ? '' : formatMinor(BigInt(minor)));
const form = useForm({ object_type: '', min_amount: '', max_amount: '', roles: [''] as string[], effective_from: props.today, effective_to: '' });
const endForm = useForm({ effective_to: props.today });
const errors = computed(() => form.errors as Record<string, string>);
const roleOptions = computed(() => props.roles.map((r) => ({ value: r.code, label: r.name })));

function openNew(): void {
    editing.value = null;
    form.defaults({ object_type: props.objectTypes[0]?.value ?? '', min_amount: '', max_amount: '', roles: [''], effective_from: props.today, effective_to: '' });
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function openChange(row: PolicyRow): void {
    editing.value = row;
    form.defaults({ object_type: row.object_type, min_amount: major(row.min_amount_minor), max_amount: major(row.max_amount_minor), roles: row.steps.map((s) => s.role ?? ''),
        effective_from: row.status === 'scheduled' ? row.effective_from : props.today, effective_to: row.effective_to ?? '' });
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function openEnd(row: PolicyRow): void {
    ending.value = row;
    endForm.effective_to = props.today;
    endForm.clearErrors();
    endOpen.value = true;
}
function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };
    form.transform((data) => ({ ...data, effective_to: data.effective_to || null }));
    if (editing.value) form.put(`/admin/approval-limits/${editing.value.id}`, options);
    else form.post('/admin/approval-limits', options);
}
function submitEnd(): void {
    if (ending.value) endForm.post(`/admin/approval-limits/${ending.value.id}/end`, { preserveScroll: true, onSuccess: () => (endOpen.value = false) });
}

const columns: DataColumn<PolicyRow>[] = [
    { id: 'label', header: 'What it approves', value: (p) => p.label, width: 220, filterOptions: props.objectTypes.map((t) => t.label) },
    { id: 'amount', header: `Amount (${props.currency})`, value: (p) => p.amount, width: 240 },
    { id: 'approvers', header: 'Approved by, in order', value: (p) => p.approvers, width: 280 },
    { id: 'from', header: 'From', type: 'date', value: (p) => p.effective_from, width: 120 },
    { id: 'to', header: 'Until', type: 'date', value: (p) => p.effective_to, width: 120 },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status, filterOptions: ['in_force', 'scheduled', 'ended'] },
];
</script>

<template>
    <AppLayout help="limits" title="Approval limits" fill>
        <QueueView
            id="admin-approval-limits"
            v-model:active="active"
            title="Approval limits"
            :columns="columns"
            :rows="policies"
            :row-key="(p) => p.id"
            empty-text="No approval limits yet, so one checker other than the maker approves everything."
            :action="{ label: 'Add approval limit' }"
            :inspector-title="(p) => p.label"
            :inspector-subtitle="(p) => p.amount"
            @action="openNew"
        >
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Amount', value: row.amount },
                        { label: 'From', value: formatDate(row.effective_from) },
                        { label: 'Until', value: row.effective_to ? `${formatDate(row.effective_to)} (not included)` : 'No end date' },
                        { label: 'Journal kinds', value: row.kinds ? row.kinds.join(', ') : null },
                    ]"
                />
                <h3 class="mt-4 mb-1 text-ui font-medium">Approval steps</h3>
                <ol class="grid list-decimal gap-1 pl-5 text-ui">
                    <li v-for="(step, i) in row.steps" :key="i">{{ step.role === null ? `Anyone with ${step.permission}` : (step.role_name ?? `${step.role} (role removed)`) }}</li>
                </ol>
                <div v-if="row.status !== 'ended'" class="mt-4 flex gap-2">
                    <Button variant="secondary" @click="openChange(row)">Change</Button>
                    <Button variant="ghost" @click="openEnd(row)">End</Button>
                </div>
            </template>
        </QueueView>

        <Drawer v-model:open="drawerOpen" :title="editing ? `Change: ${editing.label}` : 'Add approval limit'" width="w-[480px]">
            <p class="mb-4 text-ui text-ink-2">
                {{ editing && editing.status === 'in_force' ? 'The current limit ends the day this change starts, so approvals already made keep their rules.' : `Amounts are in ${currency}. Leave an amount empty for no limit on that side.` }}
            </p>
            <FormLayout :submit-label="editing ? 'Save change' : 'Add limit'" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="drawerOpen = false">
                <Field id="object_type" label="What it approves" :error="errors.object_type">
                    <SelectInput id="object_type" v-model="form.object_type" :options="objectTypes" :disabled="editing !== null" />
                </Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="min_amount" label="From amount" hint="Included" optional :error="errors.min_amount"><MoneyInput id="min_amount" v-model="form.min_amount" /></Field>
                    <Field id="max_amount" label="Below amount" hint="Not included" optional :error="errors.max_amount"><MoneyInput id="max_amount" v-model="form.max_amount" /></Field>
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-ui font-medium">Approved by, in order</legend>
                    <div v-for="(_, i) in form.roles" :key="i" class="grid grid-cols-[minmax(0,1fr)_32px] items-start gap-2">
                        <Field :id="`role_${i}`" :label="`Step ${i + 1}`" :error="errors[`roles.${i}`]">
                            <SelectInput :id="`role_${i}`" v-model="form.roles[i]" placeholder="Choose a role" :options="roleOptions" />
                        </Field>
                        <Button variant="ghost" size="icon" class="mt-6" :aria-label="`Remove step ${i + 1}`" :disabled="form.roles.length === 1" @click="form.roles.splice(i, 1)"><X :size="16" /></Button>
                    </div>
                    <p v-if="errors.roles" class="text-dense text-danger" role="alert">{{ errors.roles }}</p>
                    <div><Button variant="ghost" size="sm" :disabled="form.roles.length >= 5" @click="form.roles.push('')"><Plus :size="16" /> Add a step</Button></div>
                </fieldset>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="effective_from" label="From" :error="errors.effective_from"><DateInput id="effective_from" v-model="form.effective_from" /></Field>
                    <Field id="effective_to" label="Until" hint="Not included" optional :error="errors.effective_to"><DateInput id="effective_to" v-model="form.effective_to" /></Field>
                </div>
            </FormLayout>
        </Drawer>

        <Drawer v-model:open="endOpen" :title="ending ? `End: ${ending.label}` : 'End approval limit'">
            <p class="mb-4 text-ui text-ink-2">From this date the limit no longer applies. Approvals already requested keep it.</p>
            <FormLayout submit-label="End limit" :dirty="endForm.isDirty" :processing="endForm.processing" :error="(endForm.errors as Record<string, string>).form" @submit="submitEnd" @cancel="endOpen = false">
                <Field id="end_effective_to" label="No longer applies from" :error="endForm.errors.effective_to"><DateInput id="end_effective_to" v-model="endForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
