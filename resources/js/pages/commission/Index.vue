<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

interface Plan { id: string; code: string; name: string; rate_percent: string; withholding: string | null; status: string }
interface Statement { id: string; number: string; agent_code: string; agent_id: string; up_to: string; gross: string; withholding: string; net: string; status: string; paid_on: string | null }
const props = defineProps<{ plans: Plan[]; statements: Statement[]; agents: { id: string; code: string }[]; bankAccounts: { id: string; bank_name: string; account_no_masked: string }[]; can: { plans: boolean; approve: boolean; pay: boolean } }>();

const view = ref<'statements' | 'plans'>('statements');
const active = ref<string | null>(null);
const drawer = ref<'approve' | 'plan' | null>(null);
const planForm = useForm({ code: '', name: '', rate_percent: '', withholding_jurisdiction: '', withholding_tax_type: '' });
const approveForm = useForm({ agent_id: '', up_to: '', on: '' });
const pay = ref({ paid_on: '', bank_account_id: '' });
const confirm = useJournalConfirm();
const payable = (s: Statement) => s.status === 'approved' && props.can.pay;
const statementColumns: DataColumn<Statement>[] = [
    { id: 'number', header: 'Statement', value: (s) => s.number, width: 150 },
    { id: 'agent', header: 'Agent', value: (s) => s.agent_code, href: (s) => `/commission/agents/${s.agent_id}`, width: 100 },
    { id: 'up_to', header: 'Earned up to', type: 'date', value: (s) => s.up_to },
    { id: 'gross', header: 'Gross', type: 'money', value: (s) => s.gross, total: true },
    { id: 'withholding', header: 'Tax withheld', type: 'money', value: (s) => s.withholding, total: true },
    { id: 'net', header: 'Net', type: 'money', value: (s) => s.net, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (s) => s.status, filterOptions: ['approved', 'paid'] },
    { id: 'paid_on', header: 'Paid on', type: 'date', value: (s) => s.paid_on },
];
const planColumns: DataColumn<Plan>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, width: 110 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 240 },
    { id: 'rate', header: 'Rate (%)', type: 'number', value: (p) => p.rate_percent, width: 100 },
    { id: 'withholding', header: 'Withholding', value: (p) => p.withholding ?? 'None', width: 160, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status },
];
const action = computed(() => (view.value === 'statements' ? (props.can.approve ? { label: 'Approve a payout' } : null) : props.can.plans ? { label: 'New plan' } : null));

function payStatement(s: Statement): void {
    void confirm.request(`/commission/statements/${s.id}/pay`, pay.value, `Pay ${s.number} to agent ${s.agent_code}?`, `Pay ${formatMoney(s.net)} BDT`);
}
</script>

<template>
    <AppLayout help="commission" title="Commission" fill>
        <div class="flex h-9 items-end gap-4 border-b border-line px-4" role="tablist" aria-label="Commission">
            <button v-for="tab in [{ id: 'statements', label: 'Payout statements' }, { id: 'plans', label: 'Plans' }] as const" :key="tab.id" type="button" role="tab" :aria-selected="view === tab.id"
                class="-mb-px h-8 border-b-2 text-ui" :class="view === tab.id ? 'border-accent text-ink' : 'border-transparent text-ink-2 hover:text-ink'" @click="view = tab.id; active = null">
                {{ tab.label }}
            </button>
        </div>
        <QueueView
            v-if="view === 'statements'"
            id="commission-statements"
            v-model:active="active"
            title="Commission"
            :columns="statementColumns"
            :rows="statements"
            :row-key="(s) => s.id"
            currency="BDT"
            empty-text="No payout statements yet. Approve one for an agent's earned commission."
            :action="action"
            :inspector-title="(s) => s.number"
            :inspector-subtitle="(s) => `Agent ${s.agent_code} · earned up to ${formatDate(s.up_to)}`"
            :primary-label="(s) => (payable(s) ? 'Pay the statement' : undefined)"
            @action="drawer = 'approve'"
            @primary="payStatement"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Gross', value: `${formatMoney(row.gross)} BDT`, num: true }, { label: 'Tax withheld', value: `${formatMoney(row.withholding)} BDT`, num: true }, { label: 'Net to pay', value: `${formatMoney(row.net)} BDT`, num: true }, { label: 'Paid on', value: formatDate(row.paid_on) }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div v-if="payable(row)" class="mt-4 grid gap-3 border-t border-line pt-4">
                    <Field id="paid_on" label="Paid on" hint="The person who approved the statement cannot pay it."><DateInput v-model="pay.paid_on" /></Field>
                    <Field id="bank_account_id" label="Pay from" optional><SelectInput id="bank_account_id" v-model="pay.bank_account_id" placeholder="Default bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" /></Field>
                </div>
                <Link :href="`/commission/agents/${row.agent_id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Agent statement</Link>
            </template>
        </QueueView>
        <DataTable v-else id="commission-plans" label="Commission plans" :columns="planColumns" :rows="plans" :row-key="(p) => p.id" :url-sync="false" empty-text="No plans yet.">
            <template #toolbar>
                <h1 class="mr-3 text-section font-semibold">Commission plans</h1>
                <button v-if="action" type="button" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="drawer = 'plan'">{{ action.label }}</button>
            </template>
        </DataTable>

        <Drawer :open="drawer === 'approve'" title="Approve a payout" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Approve statement" :dirty="approveForm.isDirty" :processing="approveForm.processing" :error="(approveForm.errors as Record<string, string>).form" @submit="approveForm.post('/commission/statements', { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <Field id="agent_id" label="Agent" :error="approveForm.errors.agent_id"><SelectInput id="agent_id" v-model="approveForm.agent_id" placeholder="Choose an agent" :options="agents.map((a) => ({ value: a.id, label: a.code }))" /></Field>
                <Field id="up_to" label="Commission earned up to" :error="approveForm.errors.up_to"><DateInput v-model="approveForm.up_to" /></Field>
                <Field id="on" label="Approval date" :error="approveForm.errors.on"><DateInput v-model="approveForm.on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'plan'" title="New commission plan" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Create plan" :dirty="planForm.isDirty" :processing="planForm.processing" :error="(planForm.errors as Record<string, string>).form" @submit="planForm.post('/commission/plans', { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <Field id="plan-code" label="Code" :error="planForm.errors.code"><TextInput v-model="planForm.code" /></Field>
                <Field id="plan-name" label="Name" :error="planForm.errors.name"><TextInput v-model="planForm.name" /></Field>
                <Field id="rate_percent" label="Rate (%)" :error="planForm.errors.rate_percent"><TextInput v-model="planForm.rate_percent" inputmode="decimal" placeholder="10.00" /></Field>
                <Field id="withholding_tax_type" label="Withholding tax type" optional hint="Leave empty when no tax is withheld." :error="planForm.errors.withholding_tax_type"><TextInput v-model="planForm.withholding_tax_type" /></Field>
                <Field id="withholding_jurisdiction" label="Withholding jurisdiction" optional :error="planForm.errors.withholding_jurisdiction"><TextInput v-model="planForm.withholding_jurisdiction" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
