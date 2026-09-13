<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    asOf: string;
    ageing: { buckets: Record<string, string>; total: string; items: { id: string; receipt_id: string; receipt_number: string; reference: string | null; aged_since: string; days: number; open: string }[] };
    installments: { id: string; label: string; outstanding: string }[];
}>();

const asOf = ref(props.asOf);
const allocating = ref<string | null>(null);
const form = useForm({ installment_id: '', amount: '', on: props.asOf });
</script>

<template>
    <AppLayout title="Suspense">
        <PageHeader eyebrow="Collections" title="Suspense" :description="`Unidentified money waiting for allocation: ${ageing.total} open as of ${asOf}.`">
            <form class="flex gap-2" @submit.prevent="router.get('/suspense', { as_of: asOf }, { preserveState: true })">
                <Input v-model="asOf" type="date" class="w-40" aria-label="As of" /><Button type="submit" variant="ghost">Show</Button>
            </form>
        </PageHeader>
        <FormBanner />
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Card v-for="(amount, bucket) in ageing.buckets" :key="bucket"><p class="text-dense text-ink-2">{{ bucket }} days</p><p class="mt-1 text-section tabular-nums">{{ amount }}</p></Card>
        </div>
        <Table>
            <TableHeader><TableRow><TableHead>Receipt</TableHead><TableHead>Reference</TableHead><TableHead>Since</TableHead><TableHead>Days</TableHead><TableHead class="text-right">Open</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
                <template v-for="item in ageing.items" :key="item.id">
                    <TableRow>
                        <TableCell><Link :href="`/receipts/${item.receipt_id}`" class=" text-accent-text hover:underline">{{ item.receipt_number }}</Link></TableCell>
                        <TableCell class="text-ink-2">{{ item.reference }}</TableCell><TableCell>{{ item.aged_since }}</TableCell><TableCell>{{ item.days }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ item.open }}</TableCell>
                        <TableCell class="text-right"><Button variant="ghost" @click="allocating = allocating === item.id ? null : item.id">Allocate</Button></TableCell>
                    </TableRow>
                    <TableRow v-if="allocating === item.id">
                        <TableCell :colspan="6">
                            <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="form.post(`/suspense/${item.id}/allocate`, { onSuccess: () => { allocating = null; form.reset('installment_id', 'amount'); } })">
                                <div class="sm:col-span-2"><Field id="installment_id" label="Installment" :error="form.errors.installment_id"><SelectInput id="installment_id" v-model="form.installment_id" placeholder="Choose" :options="installments.map((i) => ({ value: i.id, label: `${i.label} · ${i.outstanding}` }))" /></Field></div>
                                <Field id="alloc_amount" label="Amount" :error="form.errors.amount"><Input id="alloc_amount" v-model="form.amount" inputmode="decimal" /></Field>
                                <Field id="alloc_on" label="Date" :error="form.errors.on"><Input id="alloc_on" v-model="form.on" type="date" /></Field>
                                <Button type="submit" :disabled="form.processing" class="justify-self-start">Allocate</Button>
                            </form>
                        </TableCell>
                    </TableRow>
                </template>
                <TableEmpty v-if="ageing.items.length === 0" :colspan="6">Nothing in suspense.</TableEmpty>
            </TableBody>
        </Table>
    </AppLayout>
</template>
