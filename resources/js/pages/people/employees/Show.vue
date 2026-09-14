<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AuditRow } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { changeKindLabel, employmentTypeLabel, employmentTypes, type PeopleOptions } from '@/lib/people';

/** Addendum §B.9.7 employee page: Overview · Employment history · Payslips · Audit, with a Change drawer for promotions, transfers and pay changes. */
interface HistoryRow { from: string; to: string | null; kind: string; branch: string; department: string; designation: string; grade: string; type: string; basic: string; note: string | null }
interface PayslipRow { id: string; number: string | null; run_id: string; period: string; status: string; gross: string; tax: string; commission: string; net: string }
const props = defineProps<{
    employee: { id: string; code: string; name: string; status: string; joined_on: string; mobile: string | null; date_of_birth: string | null; gender: string | null; tin: string | null; nid: string | null; bank: string | null; routing_no: string | null };
    current: { branch_id: string; department_id: string; designation_id: string; grade_id: string; employment_type: string; basic: string } | null;
    facts: { label: string; value: string }[];
    history: HistoryRow[];
    payslips: PayslipRow[];
    producer: { id: string; code: string; type: string } | null;
    options: PeopleOptions;
    can: { manage: boolean };
    audit?: AuditRow[];
}>();

const changing = ref(false);
const title = computed(() => `${props.employee.code} · ${props.employee.name}`);
const subtitle = computed(() => [`Joined ${formatDate(props.employee.joined_on)}`, props.current ? employmentTypeLabel(props.current.employment_type) : null, props.producer ? `producer ${props.producer.code}` : null].filter(Boolean).join(' · '));
const facts = computed(() => props.facts.map((f) => (f.label === 'Basic salary' ? { label: 'Basic salary (BDT)', value: formatMoney(f.value), num: true } : f)));
const choices = (list: { id: string; label: string }[]) => list.map((o) => ({ value: o.id, label: o.label }));
const form = useForm({ kind: 'promotion', effective_from: useBusinessToday(), branch_id: props.current?.branch_id ?? '', department_id: props.current?.department_id ?? '', designation_id: props.current?.designation_id ?? '',
    grade_id: props.current?.grade_id ?? '', employment_type: props.current?.employment_type ?? 'permanent', basic: '', note: '' });
function submit(): void {
    form.post(`/people/employees/${props.employee.id}/employment`, { preserveScroll: true, onSuccess: () => { changing.value = false; form.reset('basic', 'note'); } });
}
const historyColumns: DataColumn<HistoryRow>[] = [
    { id: 'from', header: 'From', type: 'date', value: (h) => h.from },
    { id: 'to', header: 'To', value: (h) => (h.to ? formatDate(h.to) : 'In force'), width: 100 },
    { id: 'kind', header: 'Change', value: (h) => changeKindLabel(h.kind), width: 110 },
    { id: 'designation', header: 'Designation', value: (h) => h.designation, width: 170 },
    { id: 'department', header: 'Department', value: (h) => h.department, width: 150 },
    { id: 'branch', header: 'Branch', value: (h) => h.branch, width: 70 },
    { id: 'grade', header: 'Grade', value: (h) => h.grade, width: 60 },
    { id: 'type', header: 'Type', value: (h) => employmentTypeLabel(h.type), width: 90 },
    { id: 'basic', header: 'Basic salary', type: 'money', value: (h) => h.basic },
    { id: 'note', header: 'Note', value: (h) => h.note, width: 200, muted: true },
];
const payslipColumns: DataColumn<PayslipRow>[] = [
    { id: 'period', header: 'Month', value: (p) => formatMonth(p.period, 'long'), href: (p) => `/people/payroll/${p.run_id}`, width: 130 },
    { id: 'number', header: 'Payslip', value: (p) => p.number ?? 'Preview', width: 150, muted: true },
    { id: 'gross', header: 'Gross', type: 'money', value: (p) => p.gross, total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (p) => p.commission, total: true },
    { id: 'tax', header: 'Tax', type: 'money', value: (p) => p.tax, total: true },
    { id: 'net', header: 'Net pay', type: 'money', value: (p) => p.net, total: true },
    { id: 'status', header: 'Run', type: 'status', value: (p) => p.status, filterOptions: ['preview', 'posted', 'paid'] },
    { id: 'pdf', header: 'PDF', value: () => 'PDF', href: (p) => `/people/payslips/${p.id}/pdf`, width: 60 },
];
</script>

<template>
    <AppLayout :title="title">
        <ObjectPage
            :title="title"
            :subtitle="subtitle"
            :status="employee.status"
            :facts="facts"
            :crumbs="[{ label: 'Employees', href: '/people/employees' }]"
            currency="BDT"
            :audit="audit"
            :extra-tabs="[{ value: 'history', label: 'Employment history' }, { value: 'payslips', label: 'Payslips' }]"
            :hidden-tabs="['accounting', 'timeline', 'documents']"
        >
            <template #actions>
                <Button v-if="can.manage && employee.status === 'active'" variant="primary" @click="changing = true">Change employment</Button>
            </template>
            <template #overview>
                <FormBanner />
                <div class="grid max-w-[1100px] gap-6 lg:grid-cols-2">
                    <section>
                        <h2 class="mb-2 text-section font-semibold">Personal</h2>
                        <DetailList :items="[{ label: 'Mobile', value: employee.mobile ?? '—' }, { label: 'Date of birth', value: employee.date_of_birth ? formatDate(employee.date_of_birth) : '—' },
                            { label: 'Gender', value: employee.gender ?? '—' }, { label: 'TIN', value: employee.tin ?? '—' }, { label: 'NID', value: employee.nid ?? '—' }]" />
                    </section>
                    <section>
                        <h2 class="mb-2 text-section font-semibold">Salary account</h2>
                        <DetailList :items="[{ label: 'Account', value: employee.bank ?? 'None: payroll cannot be approved until one is added' }, { label: 'Routing number', value: employee.routing_no ?? '—' },
                            { label: 'Basic salary', value: current ? `${formatMoney(current.basic)} BDT` : '—', num: true }]" />
                        <p v-if="producer" class="mt-3 text-ui text-ink-2">Also a producer: <Link :href="`/distribution/producers/${producer.id}`" class="text-accent-text hover:underline">{{ producer.code }}</Link></p>
                    </section>
                </div>
            </template>
            <template #tab-history>
                <div class="max-w-[1200px]">
                    <DataTable id="people-employee-history" label="Employment history" :columns="historyColumns" :rows="history" :row-key="(h) => `${h.from}-${h.kind}`" currency="BDT" :url-sync="false" empty-text="No employment recorded yet." compact-toolbar />
                    <p class="mt-2 text-dense text-ink-2">History is never edited: a change ends the record in force the day before the new one starts.</p>
                </div>
            </template>
            <template #tab-payslips>
                <div class="max-w-[1100px]">
                    <DataTable id="people-employee-payslips" label="Payslips" :columns="payslipColumns" :rows="payslips" :row-key="(p) => p.id" currency="BDT" :url-sync="false" empty-text="No payslips yet: they appear when a payroll is calculated." compact-toolbar />
                </div>
            </template>
        </ObjectPage>

        <Drawer v-model:open="changing" title="Change employment" width="w-[480px]">
            <FormLayout submit-label="Record change" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="submit" @cancel="changing = false">
                <div class="grid grid-cols-2 gap-3">
                    <Field id="kind" label="Change" :error="form.errors.kind"><SelectInput id="kind" v-model="form.kind" :options="[{ value: 'promotion', label: 'Promotion' }, { value: 'transfer', label: 'Transfer' }, { value: 'pay_change', label: 'Pay change' }]" /></Field>
                    <Field id="effective_from" label="Effective from" :error="form.errors.effective_from"><DateInput v-model="form.effective_from" /></Field>
                </div>
                <Field id="designation_id" label="Designation" :error="form.errors.designation_id"><SelectInput id="designation_id" v-model="form.designation_id" :options="choices(options.designations)" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="choices(options.branches)" /></Field>
                    <Field id="department_id" label="Department" :error="form.errors.department_id"><SelectInput id="department_id" v-model="form.department_id" :options="choices(options.departments)" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="grade_id" label="Grade" :error="form.errors.grade_id"><SelectInput id="grade_id" v-model="form.grade_id" :options="choices(options.grades)" /></Field>
                    <Field id="employment_type" label="Employment type" :error="form.errors.employment_type"><SelectInput id="employment_type" v-model="form.employment_type" :options="employmentTypes.map((t) => ({ value: t, label: employmentTypeLabel(t) }))" /></Field>
                </div>
                <Field id="basic" label="New basic salary" optional :hint="current ? `Now ${formatMoney(current.basic)} BDT. Empty keeps it.` : undefined" :error="form.errors.basic"><MoneyInput id="basic" v-model="form.basic" /></Field>
                <Field id="note" label="Note" optional :error="form.errors.note"><TextInput v-model="form.note" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
