<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    agent: { id: string; code: string };
    from: string;
    to: string;
    statement: { opening_payable: string; closing_payable: string; entries: { id: string; earned_on: string; kind: string; policy_number: string | null; base: string; rate_percent: string | null; amount: string; withholding: string; net: string; status: string }[]; totals: { earned: string; clawback: string; withholding: string; net: string } };
}>();
const range = reactive({ from: props.from, to: props.to });
</script>

<template>
    <AppLayout :title="`Agent ${agent.code}`">
        <PageHeader eyebrow="Commission statement" :title="`Agent ${agent.code}`" :description="`Payable ${statement.opening_payable} at the start, ${statement.closing_payable} at the end.`">
            <form class="flex gap-2" @submit.prevent="router.get(`/commission/agents/${props.agent.id}`, range, { preserveState: true })">
                <Input v-model="range.from" type="date" class="w-40" aria-label="From" /><Input v-model="range.to" type="date" class="w-40" aria-label="To" /><Button type="submit" variant="ghost">Show</Button>
            </form>
            <Link href="/commission" class="text-ui text-accent-text hover:underline">Commission</Link>
        </PageHeader>
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Card><p class="text-dense text-ink-2">Earned</p><p class="mt-1 text-section tabular-nums">{{ statement.totals.earned }}</p></Card>
            <Card><p class="text-dense text-ink-2">Clawed back</p><p class="mt-1 text-section tabular-nums">{{ statement.totals.clawback }}</p></Card>
            <Card><p class="text-dense text-ink-2">Withheld</p><p class="mt-1 text-section tabular-nums">{{ statement.totals.withholding }}</p></Card>
            <Card><p class="text-dense text-ink-2">Net</p><p class="mt-1 text-section tabular-nums">{{ statement.totals.net }}</p></Card>
        </div>
        <Table>
            <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Kind</TableHead><TableHead>Policy</TableHead><TableHead class="text-right">Base</TableHead><TableHead class="text-right">Rate</TableHead><TableHead class="text-right">Commission</TableHead><TableHead class="text-right">Withheld</TableHead><TableHead class="text-right">Net</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="entry in statement.entries" :key="entry.id">
                    <TableCell>{{ entry.earned_on }}</TableCell><TableCell>{{ entry.kind }}</TableCell><TableCell class="">{{ entry.policy_number }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ entry.base }}</TableCell><TableCell class="text-right">{{ entry.rate_percent ? entry.rate_percent + '%' : '' }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ entry.amount }}</TableCell><TableCell class="text-right tabular-nums">{{ entry.withholding }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ entry.net }}</TableCell><TableCell><StatusBadge :status="entry.status" /></TableCell>
                </TableRow>
                <TableEmpty v-if="statement.entries.length === 0" :colspan="9">No commission in this period.</TableEmpty>
            </TableBody>
        </Table>
    </AppLayout>
</template>
