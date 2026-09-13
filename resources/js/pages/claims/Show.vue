<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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

const props = defineProps<{
    claim: { id: string; number: string; status: string; loss_date: string; reported_on: string; description: string; reserve: string; currency: string; status_reason: string | null; policy: { id: string; number: string | null; policyholder: string } };
    reserves: { version: number; reserve: string; delta: string; kind: string; reason: string; recorded_on: string }[];
    payments: { id: string; amount: string; status: string; approved_on: string; paid_on: string | null; can_request_release: boolean; can_release: boolean }[];
    recoveries: { type: string; amount: string; received_on: string; reference: string | null }[];
    parties: { id: string; display_name: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    actions: { reserve: boolean; approve: boolean; recover: boolean; close: boolean; reject: boolean; reopen: boolean };
}>();

const base = `/claims/${props.claim.id}`;
const open = ref<string | null>(null);
const reserveForm = useForm({ reserve: '', reason: '', on: '' });
const paymentForm = useForm({ amount: '', payee_party_id: '', on: '' });
const recoverForm = useForm({ type: 'salvage', amount: '', received_on: '', reference: '', bank_account_id: '' });
const decisionForm = useForm({ reason: '', on: '' });
const releaseForm = useForm({ paid_on: '', bank_account_id: '' });
</script>

<template>
    <AppLayout :title="claim.number">
        <PageHeader :eyebrow="`Policy ${claim.policy.number} · ${claim.policy.policyholder}`" :title="claim.number" :description="`Loss ${claim.loss_date}, reported ${claim.reported_on}. ${claim.description}`">
            <StatusBadge :status="claim.status" />
            <Link href="/claims" class="text-ui text-accent-text hover:underline">All claims</Link>
        </PageHeader>
        <FormBanner />
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <span class="mr-4 text-ui text-ink-2">Case reserve <span class=" text-section text-ink tabular-nums">{{ claim.reserve }}</span></span>
            <Button v-if="actions.reserve" variant="ghost" @click="open = 'reserve'">Set reserve</Button>
            <Button v-if="actions.approve" variant="ghost" @click="open = 'payment'">Approve payment</Button>
            <Button v-if="actions.recover" variant="ghost" @click="open = 'recover'">Record recovery</Button>
            <Button v-if="actions.close" variant="ghost" @click="open = 'close'">Close</Button>
            <Button v-if="actions.reject" variant="ghost" @click="open = 'reject'">Reject</Button>
            <Button v-if="actions.reopen" variant="ghost" @click="open = 'reopen'">Reopen</Button>
        </div>
        <Card v-if="open" class="mb-6 max-w-xl">
            <form v-if="open === 'reserve'" class="grid gap-3" @submit.prevent="reserveForm.post(`${base}/reserve`, { onSuccess: () => (open = null) })">
                <Field id="reserve" :label="`New total reserve (${claim.currency})`" :error="reserveForm.errors.reserve"><Input id="reserve" v-model="reserveForm.reserve" inputmode="decimal" /></Field>
                <Field id="reserve_reason" label="Reason" :error="reserveForm.errors.reason"><Input id="reserve_reason" v-model="reserveForm.reason" /></Field>
                <Field id="reserve_on" label="Date" :error="reserveForm.errors.on"><Input id="reserve_on" v-model="reserveForm.on" type="date" /></Field>
                <Button type="submit" :disabled="reserveForm.processing" class="justify-self-start">Save reserve</Button>
            </form>
            <form v-else-if="open === 'payment'" class="grid gap-3" @submit.prevent="paymentForm.post(`${base}/payments`, { onSuccess: () => (open = null) })">
                <Field id="payment_amount" :label="`Amount (${claim.currency})`" :error="paymentForm.errors.amount"><Input id="payment_amount" v-model="paymentForm.amount" inputmode="decimal" /></Field>
                <Field id="payee_party_id" label="Payee" :error="paymentForm.errors.payee_party_id"><SelectInput id="payee_party_id" v-model="paymentForm.payee_party_id" placeholder="Choose a payee" :options="parties.map((p) => ({ value: p.id, label: p.display_name }))" /></Field>
                <Field id="payment_on" label="Approval date" :error="paymentForm.errors.on"><Input id="payment_on" v-model="paymentForm.on" type="date" /></Field>
                <Button type="submit" :disabled="paymentForm.processing" class="justify-self-start">Approve payment</Button>
            </form>
            <form v-else-if="open === 'recover'" class="grid gap-3" @submit.prevent="recoverForm.post(`${base}/recover`, { onSuccess: () => (open = null) })">
                <Field id="recovery_type" label="Type" :error="recoverForm.errors.type"><SelectInput id="recovery_type" v-model="recoverForm.type" :options="['salvage', 'subrogation', 'third_party'].map((t) => ({ value: t, label: t.replace('_', ' ') }))" /></Field>
                <Field id="recovery_amount" :label="`Amount (${claim.currency})`" :error="recoverForm.errors.amount"><Input id="recovery_amount" v-model="recoverForm.amount" inputmode="decimal" /></Field>
                <Field id="received_on" label="Received on" :error="recoverForm.errors.received_on"><Input id="received_on" v-model="recoverForm.received_on" type="date" /></Field>
                <Field id="recovery_reference" label="Reference" :error="recoverForm.errors.reference"><Input id="recovery_reference" v-model="recoverForm.reference" /></Field>
                <Button type="submit" :disabled="recoverForm.processing" class="justify-self-start">Record recovery</Button>
            </form>
            <form v-else class="grid gap-3" @submit.prevent="decisionForm.post(`${base}/${open}`, { onSuccess: () => (open = null) })">
                <Field id="decision_reason" label="Reason" :error="decisionForm.errors.reason"><Input id="decision_reason" v-model="decisionForm.reason" /></Field>
                <Field id="decision_on" label="Date" :error="decisionForm.errors.on"><Input id="decision_on" v-model="decisionForm.on" type="date" /></Field>
                <Button type="submit" :disabled="decisionForm.processing" class="justify-self-start">{{ open === 'close' ? 'Close claim' : open === 'reject' ? 'Reject claim' : 'Reopen claim' }}</Button>
            </form>
        </Card>
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h2 class="mb-2 text-section font-semibold">Payments</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>Approved</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="payment in payments" :key="payment.id">
                            <TableCell>{{ payment.approved_on }}</TableCell><TableCell class="text-right tabular-nums">{{ payment.amount }}</TableCell>
                            <TableCell><StatusBadge :status="payment.status" /><span v-if="payment.paid_on" class="ml-2 text-dense text-ink-2">paid {{ payment.paid_on }}</span></TableCell>
                            <TableCell class="text-right">
                                <Button v-if="payment.can_request_release" variant="ghost" @click="releaseForm.post(`/claim-payments/${payment.id}/request-release`)">Request release</Button>
                                <span v-if="payment.can_release" class="flex items-center justify-end gap-2">
                                    <Input v-model="releaseForm.paid_on" type="date" class="w-40" aria-label="Paid on" />
                                    <Button @click="releaseForm.post(`/claim-payments/${payment.id}/release`)">Release</Button>
                                </span>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="payments.length === 0" :colspan="4">No payments.</TableEmpty>
                    </TableBody>
                </Table>
                <h2 class="mt-6 mb-2 text-section font-semibold">Recoveries</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>Received</TableHead><TableHead>Type</TableHead><TableHead class="text-right">Amount</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="(recovery, index) in recoveries" :key="index"><TableCell>{{ recovery.received_on }}</TableCell><TableCell>{{ recovery.type }}</TableCell><TableCell class="text-right tabular-nums">{{ recovery.amount }}</TableCell></TableRow>
                        <TableEmpty v-if="recoveries.length === 0" :colspan="3">No recoveries.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <div>
                <h2 class="mb-2 text-section font-semibold">Reserve history</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>v</TableHead><TableHead>Date</TableHead><TableHead class="text-right">Reserve</TableHead><TableHead class="text-right">Change</TableHead><TableHead>Reason</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="reserve in reserves" :key="reserve.version">
                            <TableCell class="">{{ reserve.version }}</TableCell><TableCell>{{ reserve.recorded_on }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ reserve.reserve }}</TableCell><TableCell class="text-right tabular-nums">{{ reserve.delta }}</TableCell>
                            <TableCell class="text-ink-2">{{ reserve.reason }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="reserves.length === 0" :colspan="5">No reserve yet.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
