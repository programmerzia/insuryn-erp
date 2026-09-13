<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { producerTypeCodes, producerTypeLabel } from '@/lib/distribution';
import { formatDate } from '@/lib/format';

/** Distribution design note §6 producers queue: what needs attention first (licence expiring, advance outstanding, statement pending). */
interface ProducerRow {
    id: string; code: string; name: string; type: string; status: string; channel: string; branch: string; level: string | null; parent_code: string | null;
    licence_expires_on: string | null; licence_state: 'valid' | 'expiring' | 'expired' | 'none'; advance_balance: string | null; pending_statements: number; attention: string[];
}
const props = defineProps<{
    producers: ProducerRow[];
    channels: { id: string; code: string; name: string; type: string }[];
    branches: { id: string; code: string; name: string }[];
    parties: { id: string; display_name: string }[];
    can: { manage: boolean; export_register: boolean };
}>();

const active = ref<string | null>(null);
const creating = ref(false);
const types = producerTypeCodes;
const form = useForm({ party_id: '', code: '', type: 'agent', branch_id: props.branches[0]?.id ?? '', channel_id: '', joined_on: '', employee_id: '' });
const licenceWords: Record<ProducerRow['licence_state'], string> = { valid: 'Valid', expiring: 'Expiring', expired: 'Expired', none: 'None' };
const columns: DataColumn<ProducerRow>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, href: (p) => `/distribution/producers/${p.id}`, width: 100 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 170 },
    { id: 'attention', header: 'Needs attention', value: (p) => p.attention.join(', '), width: 220, filterOptions: ['Licence expiring', 'No valid licence', 'Advance outstanding', 'Statement pending'] },
    { id: 'type', header: 'Type', value: (p) => producerTypeLabel(p.type), width: 90, filterOptions: types.map(producerTypeLabel) },
    { id: 'level', header: 'Level', value: (p) => p.level, width: 70 },
    { id: 'parent', header: 'Reports to', value: (p) => p.parent_code, width: 100, muted: true },
    { id: 'channel', header: 'Channel', value: (p) => p.channel, width: 100, muted: true },
    { id: 'branch', header: 'Branch', value: (p) => p.branch, width: 80, muted: true },
    { id: 'licence', header: 'Licence to', type: 'date', value: (p) => p.licence_expires_on },
    { id: 'advance', header: 'Advance balance', type: 'money', value: (p) => p.advance_balance, total: true },
    { id: 'statements', header: 'Pending statements', type: 'number', value: (p) => p.pending_statements || null, width: 100 },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status, filterOptions: ['applicant', 'active', 'suspended', 'terminated'] },
];
</script>

<template>
    <AppLayout title="Producers" fill>
        <QueueView
            id="distribution-producers"
            v-model:active="active"
            title="Producers"
            :columns="columns"
            :rows="producers"
            :row-key="(p) => p.id"
            currency="BDT"
            empty-text="No producers yet: add the first agent, BDO or broker."
            :action="can.manage ? { label: 'New producer' } : null"
            :inspector-title="(p) => `${p.code} · ${p.name}`"
            :inspector-subtitle="(p) => `${producerTypeLabel(p.type)} · ${p.channel} · ${p.branch}`"
            @action="creating = true"
        >
            <template v-if="can.export_register" #toolbar>
                <!-- A plain link: the browser downloads the file the authenticated route returns (same export as the API). ASSUMPTION: A-62 as of today. -->
                <div class="ml-2 flex items-center gap-2 text-ui" role="group" aria-label="Export agency register">
                    <span class="text-ink-2">Export agency register</span>
                    <a href="/distribution/licences/register?format=csv" class="text-accent-text hover:underline">CSV</a>
                    <a href="/distribution/licences/register?format=xlsx" class="text-accent-text hover:underline">XLSX</a>
                </div>
            </template>
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Status' },
                    { label: 'Needs attention', value: row.attention.join(', ') || 'Nothing' },
                    { label: 'Level', value: row.level ?? 'Not placed' },
                    { label: 'Reports to', value: row.parent_code ?? 'Nobody' },
                    { label: 'Licence', value: row.licence_expires_on ? `${licenceWords[row.licence_state]}, to ${formatDate(row.licence_expires_on)}` : 'None valid' },
                    { label: 'Advance balance', value: row.advance_balance ? `${row.advance_balance} BDT` : 'None', num: true },
                    { label: 'Pending statements', value: row.pending_statements, num: true },
                ]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/distribution/producers/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the producer</Link>
            </template>
        </QueueView>

        <Drawer v-model:open="creating" title="New producer">
            <FormLayout submit-label="Create producer" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/distribution/producers')" @cancel="creating = false">
                <Field id="party_id" label="Party" hint="The person or organisation. Create it under Parties first." :error="form.errors.party_id">
                    <SelectInput id="party_id" v-model="form.party_id" placeholder="Choose a party" :options="parties.map((p) => ({ value: p.id, label: p.display_name }))" />
                </Field>
                <Field id="code" label="Code" :error="form.errors.code"><TextInput v-model="form.code" /></Field>
                <Field id="type" label="Type" :error="form.errors.type"><SelectInput id="type" v-model="form.type" :options="types.map((t) => ({ value: t, label: producerTypeLabel(t) }))" /></Field>
                <Field id="channel_id" label="Channel" optional hint="Empty: the standard channel for the type." :error="form.errors.channel_id">
                    <SelectInput id="channel_id" v-model="form.channel_id" placeholder="Standard channel" :options="channels.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` }))" />
                </Field>
                <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` }))" /></Field>
                <Field id="joined_on" label="Joined on" optional :error="form.errors.joined_on"><DateInput v-model="form.joined_on" /></Field>
                <Field id="employee_id" label="Employee record" optional hint="Salaried producers on payroll; their payouts go through payroll." :error="form.errors.employee_id"><TextInput v-model="form.employee_id" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
