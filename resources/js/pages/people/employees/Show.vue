<script setup lang="ts">
import { Deferred, Link, useForm } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import AuditList from '@/components/object/AuditList.vue';
import SkeletonRows from '@/components/object/SkeletonRows.vue';
import type { AuditRow } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { changeKindLabel, employmentTypeLabel, employmentTypes, runStatusLabel, type PeopleOptions } from '@/lib/people';

/** Addendum §B.9.7 employee page: Overview · Employment history · Payslips · Audit, with a Change drawer for promotions, transfers and pay changes. */
interface HistoryRow { from: string; to: string | null; kind: string; branch: string; department: string; designation: string; grade: string; type: string; basic: string; note: string | null }
const props = defineProps<{
    employee: { id: string; code: string; name: string; status: string; joined_on: string; mobile: string | null; date_of_birth: string | null; gender: string | null; tin: string | null; nid: string | null; bank: string | null; routing_no: string | null };
    current: { branch_id: string; department_id: string; designation_id: string; grade_id: string; employment_type: string; basic: string } | null;
    facts: { label: string; value: string }[];
    history: HistoryRow[];
    payslips: { id: string; number: string | null; run_id: string; period: string; status: string; gross: string; tax: string; commission: string; net: string }[];
    producer: { id: string; code: string; type: string } | null;
    options: PeopleOptions;
    can: { manage: boolean };
    audit?: AuditRow[];
}>();

const tab = ref('overview');
const changing = ref(false);
const choices = (list: { id: string; label: string }[]) => list.map((o) => ({ value: o.id, label: o.label }));
const form = useForm({ kind: 'promotion', effective_from: '', branch_id: props.current?.branch_id ?? '', department_id: props.current?.department_id ?? '', designation_id: props.current?.designation_id ?? '',
    grade_id: props.current?.grade_id ?? '', employment_type: props.current?.employment_type ?? 'permanent', basic: '', note: '' });
function submit(): void {
    form.post(`/people/employees/${props.employee.id}/employment`, { preserveScroll: true, onSuccess: () => { changing.value = false; form.reset('effective_from', 'basic', 'note'); } });
}
</script>

<template>
    <AppLayout :title="`${employee.code} ${employee.name}`">
        <div class="flex min-h-full flex-col">
            <header class="border-b border-line px-6 pt-3">
                <Breadcrumb :base="[{ label: 'Employees', href: '/people/employees' }]" />
                <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pb-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <h1 class="text-title font-semibold">{{ employee.code }} · {{ employee.name }}</h1>
                            <StatusBadge :status="employee.status" />
                        </div>
                        <p class="text-ui text-ink-2">Joined {{ formatDate(employee.joined_on) }}<template v-if="current"> · {{ employmentTypeLabel(current.employment_type) }}</template>
                            <template v-if="producer"> · producer <Link :href="`/distribution/producers/${producer.id}`" class="text-accent-text hover:underline">{{ producer.code }}</Link></template></p>
                    </div>
                    <dl class="flex flex-wrap gap-x-8 gap-y-1">
                        <div v-for="fact in facts" :key="fact.label">
                            <dt class="text-dense text-ink-2">{{ fact.label }}</dt>
                            <dd class="text-ui font-medium">{{ fact.value }}</dd>
                        </div>
                    </dl>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <Button v-if="can.manage && employee.status === 'active'" variant="primary" @click="changing = true">Change employment</Button>
                    </div>
                </div>
            </header>
            <FormBanner />
            <TabsRoot v-model="tab" class="flex flex-1 flex-col">
                <TabsList class="flex gap-5 border-b border-line px-6" aria-label="Sections">
                    <TabsTrigger v-for="[value, label] in [['overview', 'Overview'], ['history', 'Employment history'], ['payslips', 'Payslips'], ['audit', 'Audit']]" :key="value" :value="value"
                        class="-mb-px h-9 border-b-2 border-transparent text-ui text-ink-2 hover:text-ink data-[state=active]:border-accent data-[state=active]:text-ink">{{ label }}</TabsTrigger>
                </TabsList>
                <div class="flex-1 px-6 py-4">
                    <TabsContent value="overview" class="grid max-w-[1100px] gap-6 outline-none lg:grid-cols-2">
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Personal</h2>
                            <DetailList :items="[{ label: 'Mobile', value: employee.mobile ?? '—' }, { label: 'Date of birth', value: employee.date_of_birth ? formatDate(employee.date_of_birth) : '—' },
                                { label: 'Gender', value: employee.gender ?? '—' }, { label: 'TIN', value: employee.tin ?? '—' }, { label: 'NID', value: employee.nid ?? '—' }]" />
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Salary account</h2>
                            <DetailList :items="[{ label: 'Account', value: employee.bank ?? 'None: payroll cannot be approved until one is added' }, { label: 'Routing number', value: employee.routing_no ?? '—' },
                                { label: 'Basic salary', value: current ? `${formatMoney(current.basic)} BDT` : '—', num: true }]" />
                        </section>
                    </TabsContent>

                    <TabsContent value="history" class="outline-none">
                        <div class="max-w-[1200px] overflow-x-auto border border-line">
                            <table class="w-full text-dense">
                                <thead class="bg-surface-2 text-left text-ink-2">
                                    <tr><th class="px-2 py-1.5">From</th><th class="px-2">To</th><th class="px-2">Change</th><th class="px-2">Designation</th><th class="px-2">Department</th><th class="px-2">Branch</th><th class="px-2">Grade</th><th class="px-2">Type</th><th class="px-2 text-right">Basic</th><th class="px-2">Note</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(h, i) in history" :key="i" class="border-t border-line">
                                        <td class="px-2 py-1.5 tabular-nums">{{ formatDate(h.from) }}</td><td class="px-2 tabular-nums text-ink-2">{{ h.to ? formatDate(h.to) : 'In force' }}</td>
                                        <td class="px-2">{{ changeKindLabel(h.kind) }}</td><td class="px-2">{{ h.designation }}</td><td class="px-2">{{ h.department }}</td><td class="px-2">{{ h.branch }}</td>
                                        <td class="px-2">{{ h.grade }}</td><td class="px-2">{{ employmentTypeLabel(h.type) }}</td><td class="px-2 text-right tabular-nums">{{ formatMoney(h.basic) }}</td><td class="px-2 text-ink-2">{{ h.note }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-dense text-ink-2">History is never edited: a change ends the record in force the day before the new one starts.</p>
                    </TabsContent>

                    <TabsContent value="payslips" class="outline-none">
                        <ul class="max-w-[900px] border border-line">
                            <li v-for="p in payslips" :key="p.id" class="flex flex-wrap items-center gap-4 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                <Link :href="`/people/payroll/${p.run_id}`" class="w-32 font-medium text-accent-text hover:underline">{{ formatMonth(p.period, 'long') }}</Link>
                                <span class="w-36 text-ink-2">{{ p.number ?? 'Preview' }}</span>
                                <span class="text-ink-2">Gross <span class="tabular-nums text-ink">{{ formatMoney(p.gross) }}</span></span>
                                <span v-if="p.commission !== '0.00'" class="text-ink-2">Commission <span class="tabular-nums text-ink">{{ formatMoney(p.commission) }}</span></span>
                                <span class="text-ink-2">Tax <span class="tabular-nums text-ink">{{ formatMoney(p.tax) }}</span></span>
                                <span class="text-ink-2">Net <span class="font-medium tabular-nums text-ink">{{ formatMoney(p.net) }}</span></span>
                                <span class="ml-auto">{{ runStatusLabel(p.status) }}</span>
                                <a :href="`/people/payslips/${p.id}/pdf`" target="_blank" class="text-accent-text hover:underline">PDF</a>
                                <a :href="`/people/payslips/${p.id}/pdf?locale=bn`" target="_blank" class="text-accent-text hover:underline">বাংলা</a>
                            </li>
                            <li v-if="payslips.length === 0" class="px-3 py-4 text-ui text-ink-2">No payslips yet.</li>
                        </ul>
                    </TabsContent>

                    <TabsContent value="audit" class="outline-none">
                        <Deferred data="audit"><template #fallback><SkeletonRows /></template><AuditList :rows="audit ?? []" /></Deferred>
                    </TabsContent>
                </div>
            </TabsRoot>
        </div>

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
