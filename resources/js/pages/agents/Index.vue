<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

interface Option { id: string; code?: string; name?: string; display_name?: string }
const props = defineProps<{
    agents: { id: string; code: string; name: string; branch_id: string; parent_agent_id: string | null; commission_plan_id: string | null; status: string }[];
    parties: Option[];
    branches: Option[];
    commissionPlans: Option[];
}>();

const form = useForm({ party_id: '', code: '', branch_id: props.branches[0]?.id ?? '', parent_agent_id: '', commission_plan_id: '' });
</script>

<template>
    <AppLayout title="Agents">
        <PageHeader eyebrow="Operations" title="Agents" description="Agents who sell and collect for the company, with their branch and commission plan." />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Table>
                    <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Plan</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="agent in agents" :key="agent.id">
                            <TableCell class="">{{ agent.code }}</TableCell>
                            <TableCell>{{ agent.name }}</TableCell>
                            <TableCell class="text-ink-2">{{ commissionPlans.find((p) => p.id === agent.commission_plan_id)?.code ?? '—' }}</TableCell>
                            <TableCell><StatusBadge :status="agent.status" /></TableCell>
                        </TableRow>
                        <TableEmpty v-if="agents.length === 0" :colspan="4">No agents yet.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <Card>
                <h2 class="text-section font-semibold">New agent</h2>
                <FormBanner />
                <form class="mt-4 grid gap-4" @submit.prevent="form.post('/agents', { onSuccess: () => form.reset('code', 'party_id') })">
                    <Field id="party_id" label="Party" :error="form.errors.party_id">
                        <SelectInput id="party_id" v-model="form.party_id" placeholder="Choose a party" :options="parties.map((p) => ({ value: p.id, label: p.display_name ?? '' }))" />
                    </Field>
                    <Field id="code" label="Agent code" :error="form.errors.code"><Input id="code" v-model="form.code" /></Field>
                    <Field id="branch_id" label="Branch" :error="form.errors.branch_id">
                        <SelectInput id="branch_id" v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` }))" />
                    </Field>
                    <Field id="commission_plan_id" label="Commission plan" :error="form.errors.commission_plan_id">
                        <SelectInput id="commission_plan_id" v-model="form.commission_plan_id" placeholder="None" :options="commissionPlans.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" />
                    </Field>
                    <Field id="parent_agent_id" label="Reports to" :error="form.errors.parent_agent_id">
                        <SelectInput id="parent_agent_id" v-model="form.parent_agent_id" placeholder="Nobody" :options="agents.map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` }))" />
                    </Field>
                    <Button type="submit" :disabled="form.processing">Create agent</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
