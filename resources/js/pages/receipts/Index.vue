<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ receipts: { data: { id: string; number: string; channel: string; amount: string; value_date: string; reference: string | null; status: string; agent_collection: boolean }[]; current_page: number; last_page: number; total: number } }>();
</script>

<template>
    <AppLayout title="Receipts">
        <PageHeader eyebrow="Collections" title="Receipts" description="Money received, newest first.">
            <Link href="/cheques" class="text-ui text-accent-text hover:underline">Cheque register</Link>
            <Link href="/receipts/create"><Button>Record receipt</Button></Link>
        </PageHeader>
        <Table>
            <TableHeader><TableRow><TableHead>Number</TableHead><TableHead>Date</TableHead><TableHead>Channel</TableHead><TableHead>Reference</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="receipt in receipts.data" :key="receipt.id">
                    <TableCell><Link :href="`/receipts/${receipt.id}`" class=" text-accent-text hover:underline">{{ receipt.number }}</Link></TableCell>
                    <TableCell>{{ receipt.value_date }}</TableCell>
                    <TableCell>{{ receipt.channel }}<span v-if="receipt.agent_collection" class="text-ink-2"> · agent</span></TableCell>
                    <TableCell class="text-ink-2">{{ receipt.reference }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ receipt.amount }}</TableCell>
                    <TableCell><StatusBadge :status="receipt.status" /></TableCell>
                </TableRow>
                <TableEmpty v-if="receipts.data.length === 0" :colspan="6">No receipts yet.</TableEmpty>
            </TableBody>
        </Table>
        <Pagination :page="receipts" />
    </AppLayout>
</template>
