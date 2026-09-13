<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ approvals: { id: string; object_type: string; title: string; step: number; requested_by: string; requested_at: string; link: string | null; amount: string | null }[] }>();
const form = useForm({ decision: 'approved', reason: '' });

function decide(id: string, decision: 'approved' | 'rejected'): void {
    form.decision = decision;
    form.post(`/approvals/${id}/decide`, { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <AppLayout title="Approvals">
        <PageHeader eyebrow="Inbox" title="Approvals" description="Requests waiting for your decision. You never see your own requests." />
        <FormBanner />
        <Table>
            <TableHeader><TableRow><TableHead>Request</TableHead><TableHead class="text-right">Amount</TableHead><TableHead>Requested by</TableHead><TableHead>Step</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="approval in approvals" :key="approval.id">
                    <TableCell><Link v-if="approval.link" :href="approval.link" class="text-accent-text hover:underline">{{ approval.title }}</Link><span v-else>{{ approval.title }}</span></TableCell>
                    <TableCell class="text-right tabular-nums">{{ approval.amount }}</TableCell>
                    <TableCell>{{ approval.requested_by }} <span class="text-dense text-ink-2">{{ approval.requested_at }}</span></TableCell>
                    <TableCell>{{ approval.step }}</TableCell>
                    <TableCell>
                        <span class="flex items-center justify-end gap-2">
                            <Button @click="decide(approval.id, 'approved')">Approve</Button>
                            <Input v-model="form.reason" placeholder="Reason to reject" class="w-44" aria-label="Reason to reject" />
                            <Button variant="ghost" @click="decide(approval.id, 'rejected')">Reject</Button>
                        </span>
                    </TableCell>
                </TableRow>
                <TableEmpty v-if="approvals.length === 0" :colspan="5">Nothing is waiting for you.</TableEmpty>
            </TableBody>
        </Table>
    </AppLayout>
</template>
