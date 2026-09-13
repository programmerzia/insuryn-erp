<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ periods: { id: string; label: string; starts: string; ends: string; status: string; run: { id: string; status: string } | null }[]; can: { start: boolean; reopen: boolean } }>();
const reopening = ref<string | null>(null);
const reopenForm = useForm({ reason: '' });
</script>

<template>
    <AppLayout title="Month-end close">
        <PageHeader eyebrow="Accounting" title="Month-end close" description="Start a period's close, work through its tasks, and lock it. Reopening needs a reason." />
        <FormBanner />
        <Table>
            <TableHeader><TableRow><TableHead>Period</TableHead><TableHead>Dates</TableHead><TableHead>Status</TableHead><TableHead>Close</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="period in periods" :key="period.id">
                    <TableCell class="">{{ period.label }}</TableCell>
                    <TableCell class="text-ink-2">{{ period.starts }} – {{ period.ends }}</TableCell>
                    <TableCell><StatusBadge :status="period.status" /></TableCell>
                    <TableCell><Link v-if="period.run" :href="`/close/runs/${period.run.id}`" class="text-accent-text hover:underline">{{ period.run.status }}</Link><span v-else class="text-ink-2">not started</span></TableCell>
                    <TableCell class="text-right">
                        <Button v-if="can.start && period.status !== 'locked' && (!period.run || period.run.status === 'reopened')" variant="ghost" @click="router.post(`/close/periods/${period.id}`)">Start close</Button>
                        <template v-if="can.reopen && period.status !== 'open'">
                            <Button v-if="reopening !== period.id" variant="ghost" @click="reopening = period.id">Reopen</Button>
                            <form v-else class="flex items-center justify-end gap-2" @submit.prevent="reopenForm.post(`/close/periods/${period.id}/reopen`, { onSuccess: () => (reopening = null) })">
                                <Input v-model="reopenForm.reason" placeholder="Reason" class="w-48" aria-label="Reason to reopen" /><Button type="submit">Reopen</Button>
                            </form>
                        </template>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </AppLayout>
</template>
