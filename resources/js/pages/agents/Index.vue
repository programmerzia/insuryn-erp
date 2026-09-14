<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { AREAS, usePermissions } from '@/lib/permissions';

interface Option { id: string; code?: string; name?: string; display_name?: string }
interface AgentRow { id: string; code: string; name: string; branch_id: string; parent_agent_id: string | null; commission_plan_id: string | null; status: string }
const props = defineProps<{ agents: AgentRow[]; parties: Option[]; branches: Option[]; commissionPlans: Option[] }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const creating = ref(false);
const form = useForm({ party_id: '', code: '', branch_id: props.branches[0]?.id ?? '', parent_agent_id: '', commission_plan_id: '' });
const branchName = (id: string) => props.branches.find((b) => b.id === id)?.name ?? '';
const planName = (id: string | null) => props.commissionPlans.find((p) => p.id === id)?.name ?? null;
const parentCode = (id: string | null) => props.agents.find((a) => a.id === id)?.code ?? null;
const columns = computed<DataColumn<AgentRow>[]>(() => [
    // GA-07: the agent statement opens only for the commission screens' permissions.
    { id: 'code', header: 'Code', value: (a) => a.code, width: 110, href: (a) => (can(...AREAS.commission) ? `/commission/agents/${a.id}` : null) },
    { id: 'name', header: 'Name', value: (a) => a.name, width: 220 },
    { id: 'branch', header: 'Branch', value: (a) => branchName(a.branch_id), width: 140 },
    { id: 'plan', header: 'Commission plan', value: (a) => planName(a.commission_plan_id), width: 180, muted: true },
    { id: 'parent', header: 'Reports to', value: (a) => parentCode(a.parent_agent_id), width: 110, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (a) => a.status },
]);
</script>

<template>
    <AppLayout title="Agents" fill>
        <QueueView
            id="agents"
            v-model:active="active"
            title="Agents"
            :columns="columns"
            :rows="agents"
            :row-key="(a) => a.id"
            empty-text="No agents yet."
            :action="can('agent.manage') ? { label: 'New agent' } : null"
            :inspector-title="(a) => `${a.code} · ${a.name}`"
            :inspector-subtitle="(a) => branchName(a.branch_id)"
            @action="creating = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Commission plan', value: planName(row.commission_plan_id) }, { label: 'Reports to', value: parentCode(row.parent_agent_id) }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
            </template>
        </QueueView>
        <Drawer v-model:open="creating" title="New agent">
            <FormLayout submit-label="Create agent" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/agents', { onSuccess: () => (creating = false) })" @cancel="creating = false">
                <Field id="party_id" label="Person or company" :error="form.errors.party_id"><SelectInput id="party_id" v-model="form.party_id" placeholder="Choose a party" :options="parties.map((p) => ({ value: p.id, label: p.display_name ?? '' }))" /></Field>
                <Field id="code" label="Agent code" :error="form.errors.code"><TextInput v-model="form.code" placeholder="AG-004" /></Field>
                <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: b.name ?? '' }))" /></Field>
                <Field id="commission_plan_id" label="Commission plan" optional :error="form.errors.commission_plan_id"><SelectInput id="commission_plan_id" v-model="form.commission_plan_id" placeholder="None" :options="commissionPlans.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" /></Field>
                <Field id="parent_agent_id" label="Reports to" optional :error="form.errors.parent_agent_id"><SelectInput id="parent_agent_id" v-model="form.parent_agent_id" placeholder="Nobody" :options="agents.map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` }))" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
