<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ from: string; to: string; notices: { id: string; policy_id: string; policy_number: string | null; payer: string; level: number; days_overdue: number; outstanding: string; issued_on: string }[] }>();
const range = reactive({ from: props.from, to: props.to });
</script>

<template>
    <AppLayout title="Dunning notices">
        <PageHeader eyebrow="Collections" title="Dunning notices" description="Payment reminders issued by the nightly run. Policies unpaid beyond the grace period lapse automatically.">
            <form class="flex gap-2" @submit.prevent="router.get('/dunning', range, { preserveState: true })">
                <Input v-model="range.from" type="date" class="w-40" aria-label="From" /><Input v-model="range.to" type="date" class="w-40" aria-label="To" /><Button type="submit" variant="ghost">Show</Button>
            </form>
        </PageHeader>
        <Table>
            <TableHeader><TableRow><TableHead>Issued</TableHead><TableHead>Policy</TableHead><TableHead>Payer</TableHead><TableHead>Reminder</TableHead><TableHead>Days overdue</TableHead><TableHead class="text-right">Outstanding</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="notice in notices" :key="notice.id">
                    <TableCell>{{ notice.issued_on }}</TableCell>
                    <TableCell><Link :href="`/policies/${notice.policy_id}`" class="font-mono text-blueprint hover:underline">{{ notice.policy_number }}</Link></TableCell>
                    <TableCell>{{ notice.payer }}</TableCell><TableCell>Level {{ notice.level }}</TableCell><TableCell>{{ notice.days_overdue }}</TableCell>
                    <TableCell class="text-right font-mono tabular-nums">{{ notice.outstanding }}</TableCell>
                </TableRow>
                <TableEmpty v-if="notices.length === 0" :colspan="6">No reminders in this period.</TableEmpty>
            </TableBody>
        </Table>
    </AppLayout>
</template>
