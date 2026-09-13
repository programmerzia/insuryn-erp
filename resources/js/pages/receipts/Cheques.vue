<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ from: string; to: string; register: { rows: { receipt_id: string; receipt_number: string; cheque_no: string; cheque_bank: string; cheque_date: string; value_date: string; amount: string; state: string; bounced_on: string | null; bounce_reason: string | null }[]; totals: { presented: string; bounced: string } } }>();
const range = reactive({ from: props.from, to: props.to });
</script>

<template>
    <AppLayout title="Cheque register">
        <PageHeader eyebrow="Collections" title="Cheque register" :description="`Presented ${register.totals.presented} · bounced ${register.totals.bounced}`">
            <form class="flex gap-2" @submit.prevent="router.get('/cheques', range, { preserveState: true })">
                <Input v-model="range.from" type="date" class="w-40" aria-label="From" /><Input v-model="range.to" type="date" class="w-40" aria-label="To" /><Button type="submit" variant="ghost">Show</Button>
            </form>
        </PageHeader>
        <Table>
            <TableHeader><TableRow><TableHead>Cheque</TableHead><TableHead>Bank</TableHead><TableHead>Received</TableHead><TableHead>Receipt</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>State</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="row in register.rows" :key="row.receipt_id">
                    <TableCell class="">{{ row.cheque_no }}</TableCell><TableCell>{{ row.cheque_bank }}</TableCell><TableCell>{{ row.value_date }}</TableCell>
                    <TableCell><Link :href="`/receipts/${row.receipt_id}`" class=" text-accent-text hover:underline">{{ row.receipt_number }}</Link></TableCell>
                    <TableCell class="text-right tabular-nums">{{ row.amount }}</TableCell>
                    <TableCell><StatusBadge :status="row.state" /><span v-if="row.bounced_on" class="ml-2 text-dense text-ink-2">{{ row.bounced_on }} · {{ row.bounce_reason }}</span></TableCell>
                </TableRow>
                <TableEmpty v-if="register.rows.length === 0" :colspan="6">No cheques in this period.</TableEmpty>
            </TableBody>
        </Table>
    </AppLayout>
</template>
