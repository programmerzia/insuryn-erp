<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
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
import { serverPage, type Paginated } from '@/lib/paging';
import { employmentTypeLabel, employmentTypes, type PeopleOptions } from '@/lib/people';
const currency = useEntityCurrency();

/** Addendum §B.9.7 employees queue: who works where, on which grade and basic, and whether pay can reach their bank. */
interface EmployeeRow {
    id: string; code: string; name: string; designation: string | null; department: string | null; branch: string | null; grade: string | null; type: string | null;
    joined_on: string; basic: string | null; status: string; bank: string | null; tin: string | null; producer_code: string | null;
}
const props = defineProps<{ employees: Paginated<EmployeeRow>; filters: { missing: string }; options: PeopleOptions; defaultBranchId: string | null; can: { manage: boolean } }>();

const active = ref<string | null>(null);
// Home's "Hire employee" opens the list with ?new=1: the hire drawer starts open for someone who may hire.
const creating = ref(props.can.manage && typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('new'));
// New employees start in the user's branch and today's business date.
const form = useForm({
    code: '', full_name: '', joined_on: useBusinessToday(), branch_id: props.defaultBranchId ?? props.options.branches[0]?.id ?? '', department_id: '', designation_id: '', grade_id: '', employment_type: 'permanent', basic: '',
    gender: '', date_of_birth: '', tin: '', nid: '', mobile: '', bank_name: '', bank_branch: '', routing_no: '', account_no: '',
});
const choices = (list: { id: string; label: string }[]) => list.map((o) => ({ value: o.id, label: o.label }));
const filterWords = props.filters.missing === 'bank' ? 'Active, without a salary account' : props.filters.missing === 'tin' ? 'Active, without a TIN' : null;
const emptyText = props.filters.missing === 'bank' ? 'Every active employee has a salary account.' : props.filters.missing === 'tin' ? 'Every active employee has a TIN.' : 'No employees yet: hire the first one.';
const columns: DataColumn<EmployeeRow>[] = [
    { id: 'code', header: 'Code', value: (e) => e.code, href: (e) => `/people/employees/${e.id}`, width: 90 },
    { id: 'name', header: 'Name', value: (e) => e.name, width: 170 },
    { id: 'designation', header: 'Designation', value: (e) => e.designation, width: 180 },
    { id: 'department', header: 'Department', value: (e) => e.department, width: 160, muted: true },
    { id: 'branch', header: 'Branch', value: (e) => e.branch, width: 70, filterOptions: props.options.branches.map((b) => b.label.split(' · ')[0] ?? b.label) },
    { id: 'grade', header: 'Grade', value: (e) => e.grade, width: 60 },
    { id: 'type', header: 'Type', value: (e) => (e.type ? employmentTypeLabel(e.type) : null), width: 90, filterOptions: employmentTypes.map(employmentTypeLabel) },
    { id: 'joined', header: 'Joined', type: 'date', value: (e) => e.joined_on },
    { id: 'basic', header: 'Basic salary', type: 'money', value: (e) => e.basic, total: true },
    { id: 'bank', header: 'Salary account', value: (e) => e.bank ?? 'Missing', width: 150, muted: true },
    { id: 'producer', header: 'Producer', value: (e) => e.producer_code, width: 90, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (e) => e.status, filterOptions: ['active', 'separated'] },
];
</script>

<template>
    <AppLayout title="Employees" fill>
        <QueueView
            id="people-employees"
            v-model:active="active"
            title="Employees"
            :columns="columns"
            :rows="employees.data"
            :page="serverPage(employees)"
            :row-key="(e) => e.id"
            :currency="currency"
            :empty-text="emptyText"
            :action="can.manage ? { label: 'Hire employee' } : null"
            :hint="can.manage ? null : 'Only the HR manager can hire employees.'"
            :empty-action="filters.missing ? { label: 'Show every employee', href: '/people/employees' } : null"
            :inspector-title="(e) => `${e.code} · ${e.name}`"
            :inspector-subtitle="(e) => [e.designation, e.branch].filter(Boolean).join(' · ')"
            @action="creating = true"
        >
            <template v-if="filterWords" #toolbar>
                <span class="ml-2 text-ui text-ink-2">{{ filterWords }}</span>
                <Link href="/people/employees" class="ml-2 text-ui text-accent-text hover:underline">Show every employee</Link>
            </template>
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Status' },
                    { label: 'Department', value: row.department ?? '—' },
                    { label: 'Grade', value: row.grade ?? '—' },
                    { label: 'Employment', value: row.type ? employmentTypeLabel(row.type) : '—' },
                    { label: 'Joined', value: formatDate(row.joined_on) },
                    { label: 'Basic salary', value: row.basic ? `${formatMoney(row.basic, currency)}` : '—', num: true },
                    { label: 'Salary account', value: row.bank ?? 'None: payroll cannot be approved' },
                    { label: 'TIN', value: row.tin ?? '—' },
                    { label: 'Producer', value: row.producer_code ?? 'Not a producer' },
                ]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/people/employees/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the employee</Link>
            </template>
        </QueueView>

        <Drawer v-model:open="creating" title="Hire employee" width="w-[520px]">
            <FormLayout submit-label="Hire" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/people/employees')" @cancel="creating = false">
                <Field id="full_name" label="Full name" :error="form.errors.full_name"><TextInput v-model="form.full_name" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="code" label="Employee code" :error="form.errors.code"><TextInput v-model="form.code" /></Field>
                    <Field id="joined_on" label="Joining date" :error="form.errors.joined_on"><DateInput v-model="form.joined_on" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="choices(options.branches)" /></Field>
                    <Field id="department_id" label="Department" :error="form.errors.department_id"><SelectInput id="department_id" v-model="form.department_id" placeholder="Choose" :options="choices(options.departments)" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="designation_id" label="Designation" :error="form.errors.designation_id"><SelectInput id="designation_id" v-model="form.designation_id" placeholder="Choose" :options="choices(options.designations)" /></Field>
                    <Field id="grade_id" label="Grade" :error="form.errors.grade_id"><SelectInput id="grade_id" v-model="form.grade_id" placeholder="Choose" :options="choices(options.grades)" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="employment_type" label="Employment type" :error="form.errors.employment_type"><SelectInput id="employment_type" v-model="form.employment_type" :options="employmentTypes.map((t) => ({ value: t, label: employmentTypeLabel(t) }))" /></Field>
                    <Field id="basic" label="Basic salary (monthly)" :error="form.errors.basic"><MoneyInput id="basic" v-model="form.basic" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="tin" label="TIN" optional :error="form.errors.tin"><TextInput v-model="form.tin" /></Field>
                    <Field id="nid" label="NID" optional :error="form.errors.nid"><TextInput v-model="form.nid" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="mobile" label="Mobile" optional :error="form.errors.mobile"><TextInput v-model="form.mobile" /></Field>
                    <Field id="date_of_birth" label="Date of birth" optional :error="form.errors.date_of_birth"><DateInput v-model="form.date_of_birth" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="bank_name" label="Salary bank" optional :error="form.errors.bank_name"><TextInput v-model="form.bank_name" /></Field>
                    <Field id="bank_branch" label="Bank branch" optional :error="form.errors.bank_branch"><TextInput v-model="form.bank_branch" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="routing_no" label="Routing number" optional :error="form.errors.routing_no"><TextInput v-model="form.routing_no" /></Field>
                    <Field id="account_no" label="Account number" optional hint="Stored encrypted, shown masked." :error="form.errors.account_no"><TextInput v-model="form.account_no" /></Field>
                </div>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
