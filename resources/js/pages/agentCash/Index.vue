<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { depositDraft } from '@/lib/drawerDefaults';
import { formatDate, formatMoney } from '@/lib/format';
import { type PreviewResult, previewJournal } from '@/lib/preview';
import { usePermissions } from '@/lib/permissions';

interface Row { agent_id: string; agent_code: string; collected: string; deposited: string; undeposited: string; gl: string; difference: string; oldest_undeposited_on: string | null; days_undeposited: number | null }
const props = defineProps<{ asOf: string; position: { rows: Row[]; totals: Record<string, string> }; agents: { id: string; code: string; name?: string | null }[]; bankAccounts: { id: string; bank_name: string; account_no_masked: string }[] }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const depositing = ref(false);
// Gap fix GA-19: a deposit starts at today with the agent's undeposited cash; the agent is looked up by code or name.
const today = useBusinessToday();
const form = useForm({ agent_id: '', amount: '', deposited_on: today, bank_account_id: '', reference: '' });
const agentPicked = ref<LookupResult | null>(null);
const pickerKey = ref(0);
const preview = ref<PreviewResult | null>(null);
const previewOpen = ref(false);
const columns: DataColumn<Row>[] = [
    { id: 'agent', header: 'Agent', value: (r) => r.agent_code, width: 110 },
    { id: 'collected', header: 'Collected', type: 'money', value: (r) => r.collected, total: true },
    { id: 'deposited', header: 'Deposited', type: 'money', value: (r) => r.deposited, total: true },
    { id: 'undeposited', header: 'Not deposited', type: 'money', value: (r) => r.undeposited, total: true },
    { id: 'gl', header: 'Ledger', type: 'money', value: (r) => r.gl },
    { id: 'difference', header: 'Difference', type: 'money', value: (r) => r.difference },
    { id: 'oldest', header: 'Oldest cash held', type: 'date', value: (r) => r.oldest_undeposited_on },
    { id: 'days', header: 'Days held', type: 'number', value: (r) => r.days_undeposited, width: 90 },
];

function agentLabel(agentId: string): LookupResult | null {
    const agent = props.agents.find((a) => a.id === agentId);
    return agent ? { id: agent.id, label: agent.name ? `${agent.code} · ${agent.name}` : agent.code } : null;
}
function openDeposit(agentId = ''): void {
    Object.assign(form, depositDraft(props.position.rows, agentId, today));
    agentPicked.value = agentLabel(agentId);
    pickerKey.value++;
    depositing.value = true;
}
watch(() => form.agent_id, (agentId, previous) => {
    const held = depositDraft(props.position.rows, agentId, today).amount;
    const previousHeld = depositDraft(props.position.rows, previous ?? '', today).amount;
    if (form.amount === '' || form.amount === previousHeld) form.amount = held;
});
async function review(): Promise<void> {
    form.clearErrors();
    const outcome = await previewJournal('/agent-cash/deposits', form.data());
    if (!outcome.ok) return void form.setError(outcome.errors as never);
    preview.value = outcome.result;
    previewOpen.value = true;
}
function post(): void {
    form.post('/agent-cash/deposits', { onSuccess: () => { depositing.value = false; form.reset(); }, onFinish: () => (previewOpen.value = false) });
}
</script>

<template>
    <AppLayout title="Agent cash" fill>
        <QueueView
            id="agent-cash"
            v-model:active="active"
            title="Agent cash"
            :columns="columns"
            :rows="position.rows"
            :row-key="(r) => r.agent_id"
            currency="BDT"
            empty-text="No agent collections."
            :action="can('receipt.create') ? { label: 'Record a deposit' } : null"
            :inspector-title="(r) => `Agent ${r.agent_code}`"
            :inspector-subtitle="(r) => `${formatMoney(r.undeposited)} BDT not deposited`"
            :primary-label="(r) => (can('receipt.create') && r.undeposited !== '0.00' ? 'Record a deposit' : undefined)"
            @action="openDeposit()"
            @primary="(r) => openDeposit(r.agent_id)"
        >
            <template #toolbar><DateRangeFilter url="/agent-cash" :as-of="asOf" /></template>
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Collected', value: `${formatMoney(row.collected)} BDT`, num: true }, { label: 'Deposited', value: `${formatMoney(row.deposited)} BDT`, num: true },
                    { label: 'Not deposited', value: `${formatMoney(row.undeposited)} BDT`, num: true }, { label: 'Ledger balance', value: `${formatMoney(row.gl)} BDT`, num: true },
                    { label: 'Difference', value: formatMoney(row.difference), num: true }, { label: 'Oldest cash held', value: row.oldest_undeposited_on ? `${formatDate(row.oldest_undeposited_on)} (${row.days_undeposited} days)` : null },
                ]" />
                <p v-if="row.difference !== '0.00'" class="mt-3 text-ui text-danger" role="alert">The agent ledger does not match the collections by {{ formatMoney(row.difference) }}. Check deposits recorded outside this screen.</p>
            </template>
        </QueueView>
        <Drawer v-model:open="depositing" title="Record a deposit">
            <FormLayout submit-label="Review and post" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="review" @cancel="depositing = false">
                <Field id="agent_id" label="Agent" :error="form.errors.agent_id"><LookupInput id="agent_id" :key="pickerKey" v-model="form.agent_id" type="agent" placeholder="Agent code or name" :initial="agentPicked" @selected="agentPicked = $event" /></Field>
                <Field id="amount" label="Amount (BDT)" hint="Starts at the cash the agent has not deposited yet." :error="form.errors.amount"><MoneyInput v-model="form.amount" /></Field>
                <Field id="deposited_on" label="Deposited on" :error="form.errors.deposited_on"><DateInput v-model="form.deposited_on" /></Field>
                <Field id="bank_account_id" label="Bank account" optional :error="form.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="form.bank_account_id" placeholder="Default bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" /></Field>
                <Field id="reference" label="Deposit slip" optional :error="form.errors.reference"><TextInput v-model="form.reference" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="previewOpen" :result="preview" title="Post this deposit?" :confirm-label="`Post deposit of ${form.amount} BDT`" currency="BDT" :processing="form.processing" @confirm="post" />
    </AppLayout>
</template>
