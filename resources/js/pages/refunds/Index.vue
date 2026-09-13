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

const props = defineProps<{
    refundable: { policy_id: string; policy_number: string | null; policyholder: string; available: string }[];
    refunds: { id: string; policy_number: string | null; amount: string; reason: string; status: string; requested_at: string; decision_reason: string | null }[];
    can: { request: boolean; release: boolean };
}>();

const requestForm = useForm({ policy_id: '', amount: '', reason: '' });
const releaseForm = useForm({ paid_on: '' });
const rejectForm = useForm({ reason: '' });
</script>

<template>
    <AppLayout title="Refunds">
        <PageHeader eyebrow="Collections" title="Refunds" description="Customer refunds after cancellation. The person who requests a refund cannot release it." />
        <FormBanner />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Table>
                    <TableHeader><TableRow><TableHead>Policy</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>Reason</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="refund in refunds" :key="refund.id">
                            <TableCell class="">{{ refund.policy_number }}</TableCell><TableCell class="text-right tabular-nums">{{ refund.amount }}</TableCell>
                            <TableCell class="text-ink-2">{{ refund.reason }}</TableCell><TableCell><StatusBadge :status="refund.status" /></TableCell>
                            <TableCell>
                                <div v-if="refund.status === 'requested' && can.release" class="flex flex-wrap items-center gap-2">
                                    <Input v-model="releaseForm.paid_on" type="date" class="w-40" aria-label="Paid on" />
                                    <Button @click="releaseForm.post(`/refunds/${refund.id}/release`)">Release</Button>
                                    <Input v-model="rejectForm.reason" placeholder="Reason to reject" class="w-40" aria-label="Reason to reject" />
                                    <Button variant="ghost" @click="rejectForm.post(`/refunds/${refund.id}/reject`)">Reject</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="refunds.length === 0" :colspan="5">No refunds yet.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <Card v-if="can.request">
                <h2 class="text-section font-semibold">Request a refund</h2>
                <form class="mt-4 grid gap-4" @submit.prevent="requestForm.post('/refunds', { onSuccess: () => requestForm.reset() })">
                    <Field id="policy_id" label="Cancelled policy" :error="requestForm.errors.policy_id">
                        <SelectInput id="policy_id" v-model="requestForm.policy_id" placeholder="Choose a policy" :options="props.refundable.map((r) => ({ value: r.policy_id, label: `${r.policy_number} · ${r.policyholder} · ${r.available} due` }))" />
                    </Field>
                    <Field id="refund_amount" label="Amount" :error="requestForm.errors.amount"><Input id="refund_amount" v-model="requestForm.amount" inputmode="decimal" /></Field>
                    <Field id="refund_reason" label="Reason" :error="requestForm.errors.reason"><Input id="refund_reason" v-model="requestForm.reason" /></Field>
                    <Button type="submit" :disabled="requestForm.processing">Request refund</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
