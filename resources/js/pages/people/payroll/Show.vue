<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { runStatusLabel } from '@/lib/people';

/**
 * Addendum §B.10.11 run page: the preview per employee (earnings, deductions, net, and how each was worked out) → approve and post (journal preview,
 * not by the preparer) → release salaries from a bank account (journal preview, not by the approver) → bank file and payslips.
 */
interface Line { kind: string; label: string; amount: string; pre_accrued: boolean }
interface Slip {
    id: string; number: string | null; employee_id: string; code: string; name: string; designation: string; department: string; branch: string; basic: string; allowances: string; bonus: string;
    commission: string; gross: string; pf: string; tax: string; net: string; employer_pf: string; bank: string | null; lines: Line[]; trace: { step: string; value: string }[];
}
interface Input { id: string; component: string; employee: string; amount: string; status: string; parked_reason: string | null; reference: string | null; pre_accrued: boolean; taxable: boolean; accrued_on: string | null }
const props = defineProps<{
    run: { id: string; number: string | null; period: string; status: string; employees: number; gross: string; bonus: string; commission: string; tax: string; pf_employee: string; pf_employer: string; net: string;
        calculated_at: string | null; posted_on: string | null; paid_on: string | null; prepared_by: string | null; approved_by: string | null; paid_by: string | null };
    payslips: Slip[];
    inputs: Input[];
    bankFiles: { id: string; version: number; name: string; total: string; items: number; generated_at: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    can: { prepare: boolean; approve: boolean; pay: boolean; approve_blocked_by_sod: boolean; pay_blocked_by_sod: boolean };
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
}>();

const opened = ref<Slip | null>(null);
const bankAccount = ref(props.bankAccounts[0]?.id ?? '');
const paidOn = ref(useBusinessToday());
const confirm = useJournalConfirm();
const month = computed(() => formatMonth(props.run.period, 'long'));
const title = computed(() => `Payroll ${month.value}`);
const subtitle = computed(() => [props.run.number ?? 'Preview, not posted', props.run.prepared_by ? `calculated by ${props.run.prepared_by}` : null,
    props.run.approved_by ? `approved by ${props.run.approved_by} (posted ${formatDate(props.run.posted_on)})` : null,
    props.run.paid_by ? `released by ${props.run.paid_by} on ${formatDate(props.run.paid_on)}` : null].filter(Boolean).join(' · '));
const zero = (v: string) => (v === '0.00' ? null : v);
const columns: DataColumn<Slip>[] = [
    { id: 'code', header: 'Code', value: (s) => s.code, href: (s) => `/people/employees/${s.employee_id}`, width: 90 },
    { id: 'name', header: 'Name', value: (s) => s.name, width: 160 },
    { id: 'department', header: 'Department', value: (s) => s.department, width: 150, muted: true },
    { id: 'branch', header: 'Branch', value: (s) => s.branch, width: 70, filterOptions: [...new Set(props.payslips.map((s) => s.branch))] },
    { id: 'basic', header: 'Basic', type: 'money', value: (s) => s.basic, total: true },
    { id: 'allowances', header: 'Allowances', type: 'money', value: (s) => s.allowances, total: true },
    { id: 'bonus', header: 'Festival bonus', type: 'money', value: (s) => zero(s.bonus), total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (s) => zero(s.commission), total: true },
    { id: 'gross', header: 'Gross', type: 'money', value: (s) => s.gross, total: true },
    { id: 'pf', header: 'PF', type: 'money', value: (s) => zero(s.pf), total: true },
    { id: 'tax', header: 'Tax', type: 'money', value: (s) => zero(s.tax), total: true },
    { id: 'net', header: 'Net pay', type: 'money', value: (s) => s.net, total: true },
    { id: 'employer_pf', header: 'Employer PF', type: 'money', value: (s) => zero(s.employer_pf), total: true },
    { id: 'bank', header: 'Account', value: (s) => s.bank ?? 'Missing', width: 120, muted: true },
];
const inputColumns: DataColumn<Input>[] = [
    { id: 'employee', header: 'Employee', value: (i) => i.employee, width: 200 },
    { id: 'component', header: 'Earning', value: (i) => (i.component === 'commission' ? `Commission statement ${i.reference ?? ''}` : i.component), width: 220 },
    { id: 'amount', header: 'Amount', type: 'money', value: (i) => i.amount, total: true },
    { id: 'accounts', header: 'In the accounts', value: (i) => (i.pre_accrued ? `Already${i.accrued_on ? ` since ${formatDate(i.accrued_on)}` : ''}` : 'Posted by this run'), width: 170, muted: true },
    { id: 'taxable', header: 'Tax', value: (i) => (i.taxable ? 'Taxed' : 'Not taxed again'), width: 120, muted: true },
    { id: 'state', header: 'State', value: (i) => (i.parked_reason ? `Parked: ${i.parked_reason === 'EMPLOYEE_UNKNOWN' ? 'employee unknown' : 'employee separated'}` : runStatusLabel(i.status === 'consumed' ? 'posted' : i.status)), width: 180 },
];
const facts = computed(() => [
    { label: 'Employees', value: String(props.run.employees), num: true }, { label: 'Gross', value: formatMoney(props.run.gross), num: true }, { label: 'Tax deducted', value: formatMoney(props.run.tax), num: true },
    { label: 'Provident fund', value: `${formatMoney(props.run.pf_employee)} + ${formatMoney(props.run.pf_employer)}`, num: true }, { label: 'Net pay (BDT)', value: formatMoney(props.run.net), num: true },
]);
function recalculate(): void {
    router.post('/people/payroll', { period: props.run.period }, { preserveScroll: true });
}
function approve(): void {
    void confirm.request(`/people/payroll/${props.run.id}/approve`, {}, `Approve and post the payroll for ${month.value}?`, `Post ${formatMoney(props.run.net)} BDT net pay`);
}
function pay(): void {
    void confirm.request(`/people/payroll/${props.run.id}/pay`, { bank_account_id: bankAccount.value, paid_on: paidOn.value }, `Release the salaries for ${month.value}?`, `Release ${formatMoney(props.run.net)} BDT`);
}
</script>

<template>
    <AppLayout :title="title">
        <ObjectPage
            :title="title"
            :subtitle="subtitle"
            :status="run.status"
            :facts="facts"
            :crumbs="[{ label: 'Payroll runs', href: '/people/payroll' }]"
            currency="BDT"
            :accounting="accounting"
            :audit="audit"
            :extra-tabs="[{ value: 'inputs', label: `Inputs (${inputs.length})` }]"
            :hidden-tabs="['timeline', 'documents']"
        >
            <template #actions>
                <Link :href="`/people/payslips?run=${run.id}`" class="text-ui text-accent-text hover:underline">Payslips</Link>
                <a v-for="f in bankFiles" :key="f.id" :href="`/people/payroll/${run.id}/bank-files/${f.id}`" class="text-ui text-accent-text hover:underline">Bank file v{{ f.version }} ({{ f.items }} payments)</a>
                <Button v-if="can.prepare" variant="secondary" @click="recalculate">Recalculate</Button>
                <Button v-if="can.approve" variant="primary" @click="approve">Approve and post</Button>
                <template v-if="can.pay">
                    <label class="sr-only" for="pay_from">Pay from</label>
                    <SelectInput id="pay_from" v-model="bankAccount" class="w-48" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" />
                    <div class="w-36"><DateInput v-model="paidOn" /></div>
                    <Button variant="primary" :disabled="!bankAccount" @click="pay">Release salaries</Button>
                </template>
            </template>
            <template #overview>
                <FormBanner />
                <p v-if="can.approve_blocked_by_sod" class="mb-3 text-ui text-ink-2">You calculated this payroll, so someone else approves it.</p>
                <p v-if="can.pay_blocked_by_sod" class="mb-3 text-ui text-ink-2">You approved this payroll, so someone else releases the salaries.</p>
                <DataTable
                    id="people-payroll-payslips"
                    label="Employees"
                    :columns="columns"
                    :rows="payslips"
                    :row-key="(s) => s.id"
                    currency="BDT"
                    :url-sync="false"
                    empty-text="Nobody is on this payroll."
                    @open="(s) => (opened = s)"
                />
                <p class="mt-2 text-dense text-ink-2">Open a row for the payslip and how it was worked out. Rules, slabs and rates are placeholders to verify (Payroll settings).</p>
            </template>
            <template #tab-inputs>
                <p class="mb-3 max-w-[900px] text-ui text-ink-2">One-off earnings for {{ month }}. Commission paid through payroll is shown on the payslip and paid, but not posted as an expense again.</p>
                <div class="max-w-[1100px]">
                    <DataTable id="people-payroll-inputs" label="Inputs" :columns="inputColumns" :rows="inputs" :row-key="(i) => i.id" currency="BDT" :url-sync="false" empty-text="No inputs this month." compact-toolbar />
                </div>
            </template>
        </ObjectPage>

        <Drawer :open="opened !== null" :title="opened ? `${opened.code} · ${opened.name}` : ''" width="w-[520px]" @update:open="(v) => { if (!v) opened = null; }">
            <div v-if="opened" class="p-4">
                <p class="mb-3 text-ui text-ink-2">{{ [opened.designation, opened.department, opened.branch].filter(Boolean).join(' · ') }}</p>
                <h3 class="mb-1 text-section font-semibold">Payslip {{ opened.number ?? '(preview)' }}</h3>
                <ul class="border border-line">
                    <li v-for="(l, i) in opened.lines" :key="i" class="flex items-center gap-2 border-b border-line px-2 py-1 text-dense last:border-b-0">
                        <span :class="l.kind === 'earning' ? '' : 'text-ink-2'">{{ l.kind === 'deduction' ? '−' : l.kind === 'employer_contribution' ? 'Employer:' : '' }} {{ l.label }}</span>
                        <span v-if="l.pre_accrued" class="text-ink-2" title="Its liability was posted when the commission statement was paid through payroll">(already in the accounts)</span>
                        <span class="ml-auto tabular-nums">{{ formatMoney(l.amount) }}</span>
                    </li>
                    <li class="flex items-center border-t border-line px-2 py-1 text-ui font-medium"><span>Net pay</span><span class="ml-auto tabular-nums">{{ formatMoney(opened.net) }} BDT</span></li>
                </ul>
                <div class="mt-2 flex gap-3 text-ui">
                    <a :href="`/people/payslips/${opened.id}/pdf`" target="_blank" class="text-accent-text hover:underline">Payslip PDF</a>
                    <a :href="`/people/payslips/${opened.id}/pdf?locale=bn`" target="_blank" class="text-accent-text hover:underline">বাংলা</a>
                    <Link :href="`/people/employees/${opened.employee_id}`" class="text-accent-text hover:underline">Open the employee</Link>
                </div>
                <h3 class="mt-5 mb-1 text-section font-semibold">How it was worked out</h3>
                <ul class="border border-line">
                    <li v-for="(t, i) in opened.trace" :key="i" class="flex items-center gap-2 border-b border-line px-2 py-1 text-dense last:border-b-0">
                        <span class="text-ink-2">{{ t.step }}</span><span class="ml-auto tabular-nums">{{ /^-?[\d,]+\.\d{2}$/.test(t.value) ? formatMoney(t.value) : t.value }}</span>
                    </li>
                </ul>
            </div>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
