<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
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

defineProps<{
    plans: { id: string; code: string; name: string; rate_percent: string; withholding: string | null; status: string }[];
    statements: { id: string; number: string; agent_code: string; agent_id: string; up_to: string; gross: string; withholding: string; net: string; status: string; paid_on: string | null }[];
    agents: { id: string; code: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    can: { plans: boolean; approve: boolean; pay: boolean };
}>();

const planForm = useForm({ code: '', name: '', rate_percent: '', withholding_jurisdiction: '', withholding_tax_type: '' });
const approveForm = useForm({ agent_id: '', up_to: '', on: '' });
const payForm = useForm({ paid_on: '', bank_account_id: '' });
</script>

<template>
    <AppLayout title="Commission">
        <PageHeader eyebrow="Operations" title="Commission" description="Plans, and payout statements: approved by one person, paid by another." />
        <FormBanner />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="grid gap-6 lg:col-span-2">
                <div>
                    <h2 class="mb-2 text-section font-semibold">Payout statements</h2>
                    <Table>
                        <TableHeader><TableRow><TableHead>Statement</TableHead><TableHead>Agent</TableHead><TableHead>Up to</TableHead><TableHead class="text-right">Net</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="statement in statements" :key="statement.id">
                                <TableCell class="">{{ statement.number }}</TableCell>
                                <TableCell><Link :href="`/commission/agents/${statement.agent_id}`" class=" text-accent-text hover:underline">{{ statement.agent_code }}</Link></TableCell>
                                <TableCell>{{ statement.up_to }}</TableCell><TableCell class="text-right tabular-nums">{{ statement.net }}</TableCell>
                                <TableCell><StatusBadge :status="statement.status" /></TableCell>
                                <TableCell class="text-right">
                                    <span v-if="statement.status === 'approved' && can.pay" class="flex items-center justify-end gap-2">
                                        <Input v-model="payForm.paid_on" type="date" class="w-40" aria-label="Paid on" />
                                        <Button @click="payForm.post(`/commission/statements/${statement.id}/pay`)">Pay</Button>
                                    </span>
                                </TableCell>
                            </TableRow>
                            <TableEmpty v-if="statements.length === 0" :colspan="6">No payout statements yet.</TableEmpty>
                        </TableBody>
                    </Table>
                </div>
                <div>
                    <h2 class="mb-2 text-section font-semibold">Plans</h2>
                    <Table>
                        <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead class="text-right">Rate</TableHead><TableHead>Withholding</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="plan in plans" :key="plan.id"><TableCell class="">{{ plan.code }}</TableCell><TableCell>{{ plan.name }}</TableCell><TableCell class="text-right">{{ plan.rate_percent }}%</TableCell><TableCell class="text-ink-2">{{ plan.withholding ?? 'none' }}</TableCell></TableRow>
                            <TableEmpty v-if="plans.length === 0" :colspan="4">No plans yet.</TableEmpty>
                        </TableBody>
                    </Table>
                </div>
            </div>
            <div class="grid content-start gap-6">
                <Card v-if="can.approve">
                    <h2 class="text-section font-semibold">Approve a payout</h2>
                    <form class="mt-4 grid gap-3" @submit.prevent="approveForm.post('/commission/statements', { onSuccess: () => approveForm.reset() })">
                        <Field id="approve_agent" label="Agent" :error="approveForm.errors.agent_id"><SelectInput id="approve_agent" v-model="approveForm.agent_id" placeholder="Choose an agent" :options="agents.map((a) => ({ value: a.id, label: a.code }))" /></Field>
                        <Field id="up_to" label="Commission earned up to" :error="approveForm.errors.up_to"><Input id="up_to" v-model="approveForm.up_to" type="date" /></Field>
                        <Field id="approve_on" label="Approval date" :error="approveForm.errors.on"><Input id="approve_on" v-model="approveForm.on" type="date" /></Field>
                        <Button type="submit" :disabled="approveForm.processing">Approve statement</Button>
                    </form>
                </Card>
                <Card v-if="can.plans">
                    <h2 class="text-section font-semibold">New plan</h2>
                    <form class="mt-4 grid gap-3" @submit.prevent="planForm.post('/commission/plans', { onSuccess: () => planForm.reset() })">
                        <Field id="plan_code" label="Code" :error="planForm.errors.code"><Input id="plan_code" v-model="planForm.code" /></Field>
                        <Field id="plan_name" label="Name" :error="planForm.errors.name"><Input id="plan_name" v-model="planForm.name" /></Field>
                        <Field id="rate_percent" label="Rate (%)" :error="planForm.errors.rate_percent"><Input id="rate_percent" v-model="planForm.rate_percent" inputmode="decimal" placeholder="10.00" /></Field>
                        <Field id="withholding_tax_type" label="Withholding tax type" hint="Leave empty for no withholding" :error="planForm.errors.withholding_tax_type"><Input id="withholding_tax_type" v-model="planForm.withholding_tax_type" /></Field>
                        <Field id="withholding_jurisdiction" label="Withholding jurisdiction" :error="planForm.errors.withholding_jurisdiction"><Input id="withholding_jurisdiction" v-model="planForm.withholding_jurisdiction" /></Field>
                        <Button type="submit" :disabled="planForm.processing">Create plan</Button>
                    </form>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
