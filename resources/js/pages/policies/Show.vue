<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    policy: { id: string; number: string | null; status: string; version: number; inception: string; expiry: string; channel: string; currency: string; policyholder: string;
        product_code: string; agent_code: string | null; gross_premium: string; net_premium: string; tax: string; cancel_date: string | null };
    transactions: { id: string; type: string; effective_date: string; premium_delta: string; reason: string | null }[];
    installments: { id: string; no: number; payer: string; due_date: string; amount: string; paid: string; credited: string; outstanding: string; status: string }[];
    payers: { name: string; share_percent: string; billed: string; paid: string; outstanding: string }[];
    actions: { issue: boolean; endorse: boolean; cancel: boolean; lapse: boolean; reinstate: boolean; renew: boolean };
}>();

const open = ref<string | null>(null);
const base = `/policies/${props.policy.id}`;
const issueForm = useForm({ on: props.policy.inception });
const endorseForm = useForm({ effective_date: '', premium_delta: '', reason: '' });
const cancelForm = useForm({ cancel_date: '', reason: '' });
const transitionForm = useForm({ reason: '' });
</script>

<template>
    <AppLayout :title="policy.number ?? 'Quote'">
        <PageHeader :eyebrow="`${policy.product_code} · ${policy.channel}${policy.agent_code ? ' · ' + policy.agent_code : ''} · v${policy.version}`" :title="policy.number ?? 'Quote'"
            :description="`${policy.policyholder} · cover ${policy.inception} – ${policy.expiry}${policy.cancel_date ? ' · cancelled from ' + policy.cancel_date : ''}`">
            <StatusBadge :status="policy.status" />
            <Link href="/policies" class="text-ui text-accent-text hover:underline">All policies</Link>
        </PageHeader>
        <FormBanner />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <Card><p class="text-dense text-ink-2">Gross premium</p><p class="mt-1 text-title tabular-nums">{{ policy.gross_premium }}</p></Card>
            <Card><p class="text-dense text-ink-2">Net premium</p><p class="mt-1 text-title tabular-nums">{{ policy.net_premium }}</p></Card>
            <Card><p class="text-dense text-ink-2">Tax</p><p class="mt-1 text-title tabular-nums">{{ policy.tax }}</p></Card>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <Button v-if="actions.issue" @click="open = 'issue'">Issue</Button>
            <Button v-if="actions.endorse" variant="ghost" @click="open = 'endorse'">Endorse</Button>
            <Button v-if="actions.cancel" variant="ghost" @click="open = 'cancel'">Cancel policy</Button>
            <Button v-if="actions.lapse" variant="ghost" @click="open = 'lapse'">Lapse</Button>
            <Button v-if="actions.reinstate" variant="ghost" @click="open = 'reinstate'">Reinstate</Button>
            <Button v-if="actions.renew" variant="ghost" @click="transitionForm.post(`${base}/renew`)">Renew</Button>
        </div>

        <Card v-if="open" class="mb-6 max-w-xl">
            <form v-if="open === 'issue'" class="grid gap-3" @submit.prevent="issueForm.post(`${base}/issue`, { onSuccess: () => (open = null) })">
                <Field id="on" label="Issue date" :error="issueForm.errors.on"><Input id="on" v-model="issueForm.on" type="date" /></Field>
                <Button type="submit" :disabled="issueForm.processing" class="justify-self-start">Issue policy</Button>
            </form>
            <form v-else-if="open === 'endorse'" class="grid gap-3" @submit.prevent="endorseForm.post(`${base}/endorse`, { onSuccess: () => { open = null; endorseForm.reset(); } })">
                <Field id="effective_date" label="Effective date" :error="endorseForm.errors.effective_date"><Input id="effective_date" v-model="endorseForm.effective_date" type="date" /></Field>
                <Field id="premium_delta" :label="`Premium change (${policy.currency})`" hint="Negative for a decrease, like -1,000.00" :error="endorseForm.errors.premium_delta"><Input id="premium_delta" v-model="endorseForm.premium_delta" inputmode="decimal" /></Field>
                <Field id="endorse_reason" label="Reason" :error="endorseForm.errors.reason"><Input id="endorse_reason" v-model="endorseForm.reason" /></Field>
                <Button type="submit" :disabled="endorseForm.processing" class="justify-self-start">Record endorsement</Button>
            </form>
            <form v-else-if="open === 'cancel'" class="grid gap-3" @submit.prevent="cancelForm.post(`${base}/cancel`, { onSuccess: () => (open = null) })">
                <Field id="cancel_date" label="Cancellation date" :error="cancelForm.errors.cancel_date"><Input id="cancel_date" v-model="cancelForm.cancel_date" type="date" /></Field>
                <Field id="cancel_reason" label="Reason" :error="cancelForm.errors.reason"><Input id="cancel_reason" v-model="cancelForm.reason" /></Field>
                <Button type="submit" :disabled="cancelForm.processing" class="justify-self-start">Cancel policy</Button>
            </form>
            <form v-else class="grid gap-3" @submit.prevent="transitionForm.post(`${base}/${open}`, { onSuccess: () => (open = null) })">
                <Field id="transition_reason" label="Reason" :error="transitionForm.errors.reason"><Input id="transition_reason" v-model="transitionForm.reason" /></Field>
                <Button type="submit" :disabled="transitionForm.processing" class="justify-self-start">{{ open === 'lapse' ? 'Lapse policy' : 'Reinstate policy' }}</Button>
            </form>
        </Card>

        <h2 class="mb-2 text-section font-semibold">Installments</h2>
        <Table class="mb-6">
            <TableHeader><TableRow><TableHead>No</TableHead><TableHead>Payer</TableHead><TableHead>Due</TableHead><TableHead class="text-right">Amount</TableHead><TableHead class="text-right">Paid</TableHead><TableHead class="text-right">Credited</TableHead><TableHead class="text-right">Outstanding</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="installment in installments" :key="installment.id">
                    <TableCell>{{ installment.no }}</TableCell><TableCell>{{ installment.payer }}</TableCell><TableCell>{{ installment.due_date }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ installment.amount }}</TableCell><TableCell class="text-right tabular-nums">{{ installment.paid }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ installment.credited }}</TableCell><TableCell class="text-right tabular-nums">{{ installment.outstanding }}</TableCell>
                    <TableCell><StatusBadge :status="installment.status" /></TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h2 class="mb-2 text-section font-semibold">Payers</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>Payer</TableHead><TableHead class="text-right">Share</TableHead><TableHead class="text-right">Billed</TableHead><TableHead class="text-right">Outstanding</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="payer in payers" :key="payer.name">
                            <TableCell>{{ payer.name }}</TableCell><TableCell class="text-right">{{ payer.share_percent }}%</TableCell>
                            <TableCell class="text-right tabular-nums">{{ payer.billed }}</TableCell><TableCell class="text-right tabular-nums">{{ payer.outstanding }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <div>
                <h2 class="mb-2 text-section font-semibold">Transactions</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>Type</TableHead><TableHead>Effective</TableHead><TableHead class="text-right">Premium change</TableHead><TableHead>Reason</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="transaction in transactions" :key="transaction.id">
                            <TableCell>{{ transaction.type }}</TableCell><TableCell>{{ transaction.effective_date }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ transaction.premium_delta }}</TableCell><TableCell class="text-ink-2">{{ transaction.reason }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
