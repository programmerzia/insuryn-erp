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
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    receipt: { id: string; number: string; channel: string; amount: string; value_date: string; reference: string | null; status: string; cheque_no: string | null; cheque_bank: string | null; bounced_on: string | null; bounce_reason: string | null };
    allocations: { id: string; policy_number: string | null; amount: string; posted_on: string; reversed_on: string | null }[];
    suspense: { id: string; amount: string; open: string; status: string } | null;
    actions: { bounce: boolean };
}>();

const bouncing = ref(false);
const bounceForm = useForm({ bounced_on: '', reason: '' });
</script>

<template>
    <AppLayout :title="receipt.number">
        <PageHeader :eyebrow="`${receipt.channel}${receipt.cheque_no ? ' · cheque ' + receipt.cheque_no + ' ' + receipt.cheque_bank : ''}`" :title="receipt.number" :description="`${receipt.amount} received ${receipt.value_date}${receipt.reference ? ' · ' + receipt.reference : ''}`">
            <StatusBadge :status="receipt.status" />
            <Link href="/receipts" class="text-sm text-blueprint hover:underline">All receipts</Link>
        </PageHeader>
        <FormBanner />
        <p v-if="receipt.bounced_on" class="mb-4 text-sm text-brick-soft">Bounced {{ receipt.bounced_on }}: {{ receipt.bounce_reason }}</p>
        <div v-if="actions.bounce" class="mb-6">
            <Button v-if="!bouncing" variant="ghost" @click="bouncing = true">Cheque bounced</Button>
            <Card v-else class="max-w-md">
                <form class="grid gap-3" @submit.prevent="bounceForm.post(`/receipts/${props.receipt.id}/bounce`)">
                    <Field id="bounced_on" label="Bounced on" :error="bounceForm.errors.bounced_on"><Input id="bounced_on" v-model="bounceForm.bounced_on" type="date" /></Field>
                    <Field id="bounce_reason" label="Bank's reason" :error="bounceForm.errors.reason"><Input id="bounce_reason" v-model="bounceForm.reason" /></Field>
                    <Button type="submit" :disabled="bounceForm.processing" class="justify-self-start">Undo this receipt</Button>
                </form>
            </Card>
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <h2 class="mb-2 text-lg font-semibold">Allocations</h2>
                <Table>
                    <TableHeader><TableRow><TableHead>Policy</TableHead><TableHead>Posted</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>Reversed</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="allocation in allocations" :key="allocation.id">
                            <TableCell class="font-mono">{{ allocation.policy_number }}</TableCell><TableCell>{{ allocation.posted_on }}</TableCell>
                            <TableCell class="text-right font-mono tabular-nums">{{ allocation.amount }}</TableCell><TableCell class="text-brick-soft">{{ allocation.reversed_on }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="allocations.length === 0" :colspan="4">Not allocated.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <Card v-if="suspense">
                <h2 class="text-lg font-semibold">Suspense</h2>
                <p class="mt-2 text-sm text-ivory-dim">Parked {{ suspense.amount }}, open <span class="font-mono text-ivory">{{ suspense.open }}</span></p>
                <StatusBadge :status="suspense.status" class="mt-2" />
                <Link href="/suspense" class="mt-3 block text-sm text-blueprint hover:underline">Allocate from suspense</Link>
            </Card>
        </div>
    </AppLayout>
</template>
