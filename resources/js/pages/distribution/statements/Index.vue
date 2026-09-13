<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { blankZero } from '@/lib/distribution';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

/**
 * Distribution design note §6 statement run workbench: pick the month, add incentive awards, prepare drafts (a preview nothing is posted from),
 * approve them, then someone else pays each by its route. Approving recovers advances; paying moves money, so both show the journal first.
 */
interface Statement { id: string; number: string | null; producer_id: string; producer_code: string; producer_name: string; paid_via: string; earned: string; override: string; bonus: string;
    clawback: string; withholding: string; advances: string; net: string; status: string; approved_by_me: boolean }
const props = defineProps<{
    periodEnd: string;
    periods: string[];
    statements: Statement[];
    entries: { statement_id: string; earned_on: string; kind: string; policy_number: string | null; amount: string; withholding: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    can: { approve: boolean; pay: boolean };
}>();

const active = ref<string | null>(null);
const on = ref('');
const bankAccount = ref('');
const confirm = useJournalConfirm();
const routeWords: Record<string, string> = { bank: 'Bank', payroll: 'Payroll', ap: 'Accounts payable' };
const monthLabel = (day: string) => new Date(`${day}T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
const drafts = computed(() => props.statements.filter((s) => s.status === 'draft').length);
const columns: DataColumn<Statement>[] = [
    { id: 'producer', header: 'Producer', value: (s) => s.producer_code, href: (s) => `/distribution/producers/${s.producer_id}`, width: 100 },
    { id: 'name', header: 'Name', value: (s) => s.producer_name, width: 160, muted: true },
    { id: 'route', header: 'Paid through', value: (s) => routeWords[s.paid_via] ?? s.paid_via, width: 130, filterOptions: Object.values(routeWords) },
    { id: 'earned', header: 'Earned', type: 'money', value: (s) => blankZero(s.earned), total: true },
    { id: 'override', header: 'Overrides', type: 'money', value: (s) => blankZero(s.override), total: true },
    { id: 'bonus', header: 'Bonus', type: 'money', value: (s) => blankZero(s.bonus), total: true },
    { id: 'clawback', header: 'Clawback', type: 'money', value: (s) => blankZero(s.clawback), total: true },
    { id: 'withholding', header: 'Tax withheld', type: 'money', value: (s) => blankZero(s.withholding), total: true },
    { id: 'advances', header: 'Advances recovered', type: 'money', value: (s) => blankZero(s.advances), total: true },
    { id: 'net', header: 'Net', type: 'money', value: (s) => s.net, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (s) => s.status, filterOptions: ['draft', 'approved', 'paid'] },
    { id: 'number', header: 'Statement', value: (s) => s.number, width: 150, muted: true },
];

function period(value: string): void {
    router.get('/distribution/statements', { period_end: value }, { preserveState: false });
}
async function prepare(): Promise<void> {
    if (drafts.value > 0 && !(await confirmAction({ title: 'Prepare again?', body: `The ${drafts.value} draft statements for ${monthLabel(props.periodEnd)} are rebuilt from the commission accrued now.`, confirmLabel: 'Prepare again' }))) return;
    router.post('/distribution/statements/prepare', { period_end: props.periodEnd }, { preserveScroll: true });
}
function primary(s: Statement): void {
    if (s.status === 'draft' && props.can.approve) {
        void confirm.request(`/distribution/statements/${s.id}/approve`, { on: on.value }, `Approve ${s.producer_code}'s statement for ${monthLabel(props.periodEnd)}?`, `Approve ${formatMoney(s.net)} BDT`);
    } else if (s.status === 'approved' && props.can.pay && !s.approved_by_me) {
        void confirm.request(`/distribution/statements/${s.id}/pay`, { paid_on: on.value, bank_account_id: bankAccount.value }, `Pay ${s.number} through ${(routeWords[s.paid_via] ?? '').toLowerCase()}?`, `Pay ${formatMoney(s.net)} BDT`);
    }
}
const primaryLabel = (s: Statement): string | undefined =>
    s.status === 'draft' && props.can.approve ? 'Approve' : s.status === 'approved' && props.can.pay && !s.approved_by_me ? 'Pay' : undefined;
</script>

<template>
    <AppLayout title="Statement run" fill>
        <QueueView
            id="distribution-statements"
            v-model:active="active"
            title="Statements"
            :columns="columns"
            :rows="statements"
            :row-key="(s) => s.id"
            currency="BDT"
            :url-sync="false"
            empty-text="No statements for this month. Prepare them from the commission accrued."
            :inspector-title="(s) => `${s.producer_code} · ${s.number ?? 'Draft'}`"
            :inspector-subtitle="(s) => `${routeWords[s.paid_via]} · ${monthLabel(periodEnd)}`"
            :primary-label="primaryLabel"
            @primary="primary"
        >
            <template #toolbar>
                <div class="ml-2 flex items-center gap-2">
                    <label class="sr-only" for="period_end">Month</label>
                    <SelectInput id="period_end" :model-value="periodEnd" class="w-44" :options="periods.map((p) => ({ value: p, label: monthLabel(p) }))" @update:model-value="(v) => period(String(v))" />
                    <Button v-if="can.approve" variant="secondary" size="sm" @click="router.post('/distribution/statements/incentives', { period_end: periodEnd }, { preserveScroll: true })">Run incentives</Button>
                    <Button v-if="can.approve" size="sm" @click="prepare">Prepare statements</Button>
                </div>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Earned', value: row.earned, num: true }, { label: 'Overrides', value: row.override, num: true }, { label: 'Bonus', value: row.bonus, num: true },
                    { label: 'Clawback', value: row.clawback, num: true }, { label: 'Tax withheld', value: row.withholding, num: true }, { label: 'Advances recovered', value: row.advances, num: true }, { label: 'Net to pay', value: `${row.net} BDT`, num: true }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div v-if="primaryLabel(row)" class="mt-4 grid gap-3 border-t border-line pt-4">
                    <Field id="statement_on" :label="row.status === 'draft' ? 'Approval date' : 'Paid on'" optional hint="Empty: today."><DateInput v-model="on" /></Field>
                    <Field v-if="row.status === 'approved' && row.paid_via === 'bank'" id="statement_bank" label="Pay from" optional>
                        <SelectInput id="statement_bank" v-model="bankAccount" placeholder="Default bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" />
                    </Field>
                </div>
                <p v-if="row.status === 'approved' && row.approved_by_me" class="mt-4 text-ui text-ink-2">You approved this statement, so someone else pays it.</p>
                <h3 class="mt-5 mb-1 text-ui font-medium">Entries</h3>
                <ul class="border border-line">
                    <li v-for="(e, i) in entries.filter((x) => x.statement_id === row.id)" :key="i" class="flex items-center gap-2 border-b border-line px-2 py-1 text-dense last:border-b-0">
                        <span class="w-20 text-ink-2">{{ formatDate(e.earned_on) }}</span><span class="w-20">{{ e.kind.charAt(0).toUpperCase() + e.kind.slice(1) }}</span><span class="truncate text-ink-2">{{ e.policy_number }}</span><span class="ml-auto tabular-nums">{{ e.amount }}</span>
                    </li>
                </ul>
                <Link :href="`/distribution/producers/${row.producer_id}?tab=statements`" class="mt-3 inline-block text-ui text-accent-text hover:underline">Open the producer</Link>
            </template>
        </QueueView>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
