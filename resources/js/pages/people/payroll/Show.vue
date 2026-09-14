<script setup lang="ts">
import { Deferred, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import AccountingList from '@/components/object/AccountingList.vue';
import AuditList from '@/components/object/AuditList.vue';
import SkeletonRows from '@/components/object/SkeletonRows.vue';
import type { AccountingJournal, AuditRow } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { runStatusLabel } from '@/lib/people';

/**
 * Addendum §B.10.11 run workbench: the preview per employee (earnings, deductions, net, and how each was worked out) → approve and post (journal preview,
 * not by the preparer) → release salaries from a bank account (journal preview, not by the approver) → bank file and payslips.
 */
interface Line { kind: string; label: string; amount: string; pre_accrued: boolean }
interface Slip {
    id: string; number: string | null; employee_id: string; code: string; name: string; designation: string; department: string; branch: string; basic: string; allowances: string; bonus: string;
    commission: string; gross: string; pf: string; tax: string; net: string; employer_pf: string; bank: string | null; lines: Line[]; trace: { step: string; value: string }[];
}
const props = defineProps<{
    run: { id: string; number: string | null; period: string; status: string; employees: number; gross: string; bonus: string; commission: string; tax: string; pf_employee: string; pf_employer: string; net: string;
        calculated_at: string | null; posted_on: string | null; paid_on: string | null; prepared_by: string | null; approved_by: string | null; paid_by: string | null };
    payslips: Slip[];
    inputs: { id: string; component: string; employee: string; amount: string; status: string; parked_reason: string | null; reference: string | null; pre_accrued: boolean; taxable: boolean; accrued_on: string | null }[];
    bankFiles: { id: string; version: number; name: string; total: string; items: number; generated_at: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    can: { prepare: boolean; approve: boolean; pay: boolean; approve_blocked_by_sod: boolean; pay_blocked_by_sod: boolean };
    journals?: AccountingJournal[];
    audit?: AuditRow[];
}>();

const active = ref<string | null>(null);
const panel = ref<'inputs' | 'journals' | 'audit' | null>(null);
const bankAccount = ref(props.bankAccounts[0]?.id ?? '');
const paidOn = ref(useBusinessToday());
const confirm = useJournalConfirm();
const month = computed(() => formatMonth(props.run.period, 'long'));
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
const facts = computed(() => [
    { label: 'Employees', value: String(props.run.employees) }, { label: 'Gross', value: formatMoney(props.run.gross) }, { label: 'Tax deducted', value: formatMoney(props.run.tax) },
    { label: 'Provident fund', value: `${formatMoney(props.run.pf_employee)} + ${formatMoney(props.run.pf_employer)}` }, { label: 'Net pay (BDT)', value: formatMoney(props.run.net) },
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
    <AppLayout :title="`Payroll ${month}`" fill>
        <div class="flex h-full min-h-0 flex-col">
            <header class="border-b border-line px-6 pt-3">
                <Breadcrumb :base="[{ label: 'Payroll runs', href: '/people/payroll' }]" />
                <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pb-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <h1 class="text-title font-semibold">Payroll {{ month }}</h1>
                            <StatusBadge :status="run.status" />
                        </div>
                        <p class="text-ui text-ink-2">{{ run.number ?? 'Preview, not posted' }} · calculated by {{ run.prepared_by }}<template v-if="run.approved_by"> · approved by {{ run.approved_by }} (posted {{ formatDate(run.posted_on) }})</template><template v-if="run.paid_by"> · released by {{ run.paid_by }} on {{ formatDate(run.paid_on) }}</template></p>
                    </div>
                    <dl class="flex flex-wrap gap-x-8 gap-y-1">
                        <div v-for="fact in facts" :key="fact.label"><dt class="text-dense text-ink-2">{{ fact.label }}</dt><dd class="text-ui font-medium tabular-nums">{{ fact.value }}</dd></div>
                    </dl>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <Button variant="secondary" size="sm" @click="panel = 'inputs'">Inputs ({{ inputs.length }})</Button>
                        <Button variant="secondary" size="sm" @click="panel = 'journals'">Journals</Button>
                        <Button variant="secondary" size="sm" @click="panel = 'audit'">Audit</Button>
                        <Link :href="`/people/payslips?run=${run.id}`" class="text-ui text-accent-text hover:underline">Payslips</Link>
                        <Button v-if="can.prepare" variant="secondary" @click="recalculate">Recalculate</Button>
                        <Button v-if="can.approve" variant="primary" @click="approve">Approve and post</Button>
                        <template v-if="can.pay">
                            <SelectInput id="pay_from" v-model="bankAccount" class="w-48" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" />
                            <div class="w-36"><DateInput v-model="paidOn" /></div>
                            <Button variant="primary" :disabled="!bankAccount" @click="pay">Release salaries</Button>
                        </template>
                        <a v-for="f in bankFiles" :key="f.id" :href="`/people/payroll/${run.id}/bank-files/${f.id}`" class="text-ui text-accent-text hover:underline">Bank file v{{ f.version }} ({{ f.items }} payments)</a>
                    </div>
                </div>
                <p v-if="can.approve_blocked_by_sod" class="pb-2 text-ui text-ink-2">You calculated this payroll, so someone else approves it.</p>
                <p v-if="can.pay_blocked_by_sod" class="pb-2 text-ui text-ink-2">You approved this payroll, so someone else releases the salaries.</p>
            </header>
            <FormBanner />
            <div class="min-h-0 flex-1">
                <QueueView
                    id="people-payroll-payslips"
                    v-model:active="active"
                    title="Employees"
                    :columns="columns"
                    :rows="payslips"
                    :row-key="(s) => s.id"
                    currency="BDT"
                    :url-sync="false"
                    empty-text="Nobody is on this payroll."
                    :inspector-title="(s) => `${s.code} · ${s.name}`"
                    :inspector-subtitle="(s) => `${s.designation} · ${s.department} · ${s.branch}`"
                >
                    <template #details="{ row }">
                        <h3 class="mb-1 text-ui font-medium">Payslip {{ row.number ?? '(preview)' }}</h3>
                        <ul class="border border-line">
                            <li v-for="(l, i) in row.lines" :key="i" class="flex items-center gap-2 border-b border-line px-2 py-1 text-dense last:border-b-0">
                                <span :class="l.kind === 'earning' ? '' : 'text-ink-2'">{{ l.kind === 'deduction' ? '−' : l.kind === 'employer_contribution' ? 'Employer:' : '' }} {{ l.label }}</span>
                                <span v-if="l.pre_accrued" class="text-ink-2" title="Its liability was posted when the commission statement was paid through payroll">(already in the accounts)</span>
                                <span class="ml-auto tabular-nums">{{ formatMoney(l.amount) }}</span>
                            </li>
                            <li class="flex items-center border-t border-line px-2 py-1 text-ui font-medium"><span>Net pay</span><span class="ml-auto tabular-nums">{{ formatMoney(row.net) }} BDT</span></li>
                        </ul>
                        <div class="mt-2 flex gap-3 text-ui">
                            <a :href="`/people/payslips/${row.id}/pdf`" target="_blank" class="text-accent-text hover:underline">Payslip PDF</a>
                            <a :href="`/people/payslips/${row.id}/pdf?locale=bn`" target="_blank" class="text-accent-text hover:underline">বাংলা</a>
                        </div>
                        <h3 class="mt-5 mb-1 text-ui font-medium">How it was worked out</h3>
                        <ul class="border border-line">
                            <li v-for="(t, i) in row.trace" :key="i" class="flex items-center gap-2 border-b border-line px-2 py-1 text-dense last:border-b-0">
                                <span class="text-ink-2">{{ t.step }}</span><span class="ml-auto tabular-nums">{{ /^-?[\d,]+\.\d{2}$/.test(t.value) ? formatMoney(t.value) : t.value }}</span>
                            </li>
                        </ul>
                        <p class="mt-2 text-dense text-ink-2">Rules, slabs and rates are placeholders to verify (Payroll settings).</p>
                    </template>
                </QueueView>
            </div>
        </div>

        <Drawer :open="panel === 'inputs'" title="Payroll inputs" width="w-[560px]" @update:open="(v) => (panel = v ? 'inputs' : null)">
            <div class="p-4">
                <p class="mb-3 text-ui text-ink-2">One-off earnings for {{ month }}. Commission paid through payroll is shown on the payslip and paid, but not posted as an expense again.</p>
                <ul class="border border-line">
                    <li v-for="i in inputs" :key="i.id" class="grid gap-1 border-b border-line px-3 py-2 text-ui last:border-b-0">
                        <div class="flex items-center gap-2"><span class="font-medium">{{ i.employee }}</span><span class="ml-auto tabular-nums">{{ formatMoney(i.amount) }}</span></div>
                        <div class="flex flex-wrap gap-x-3 text-dense text-ink-2">
                            <span>{{ i.component === 'commission' ? `Commission statement ${i.reference ?? ''}` : i.component }}</span>
                            <span v-if="i.pre_accrued">already in the accounts{{ i.accrued_on ? ` since ${formatDate(i.accrued_on)}` : '' }}</span>
                            <span>{{ i.taxable ? 'taxed' : 'not taxed again' }}</span>
                            <span>{{ i.parked_reason ? `parked: ${i.parked_reason === 'EMPLOYEE_UNKNOWN' ? 'employee unknown' : 'employee separated'}` : runStatusLabel(i.status === 'consumed' ? 'posted' : i.status) }}</span>
                        </div>
                    </li>
                    <li v-if="inputs.length === 0" class="px-3 py-4 text-ui text-ink-2">No inputs this month.</li>
                </ul>
            </div>
        </Drawer>
        <Drawer :open="panel === 'journals'" title="Journals" width="w-[640px]" @update:open="(v) => (panel = v ? 'journals' : null)">
            <div class="p-4"><Deferred data="journals"><template #fallback><SkeletonRows /></template><AccountingList :journals="journals ?? []" currency="BDT" :from="`/people/payroll/${run.id}`" /></Deferred></div>
        </Drawer>
        <Drawer :open="panel === 'audit'" title="Audit" width="w-[560px]" @update:open="(v) => (panel = v ? 'audit' : null)">
            <div class="p-4"><Deferred data="audit"><template #fallback><SkeletonRows /></template><AuditList :rows="audit ?? []" /></Deferred></div>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
