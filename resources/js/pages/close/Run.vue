<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    run: { id: string; status: string; period: string; period_status: string; started_at: string; completed_at: string | null };
    tasks: { id: string; code: string; order_no: number; owner_role: string; status: string; depends_on: string[]; summary: string | null; done_at: string | null }[];
}>();

const notes = reactive<Record<string, string>>({});
const form = useForm({ note: '', reason: '' });
const label = (code: string): string => code.replace(/_/g, ' ');

function execute(id: string): void {
    form.note = notes[id] ?? '';
    form.post(`/close/tasks/${id}/execute`, { preserveScroll: true });
}

function skip(id: string): void {
    form.reason = notes[id] ?? '';
    form.post(`/close/tasks/${id}/skip`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`Close ${run.period}`">
        <PageHeader eyebrow="Month-end close" :title="`Close ${run.period}`" :description="`Run ${run.status}; period ${run.period_status}. Tasks run once their dependencies are done or skipped.`">
            <StatusBadge :status="run.status" />
            <Link href="/close" class="text-ui text-accent-text hover:underline">All periods</Link>
        </PageHeader>
        <FormBanner />
        <Table>
            <TableHeader><TableRow><TableHead>#</TableHead><TableHead>Task</TableHead><TableHead>Owner</TableHead><TableHead>Status</TableHead><TableHead>Result</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="task in props.tasks" :key="task.id">
                    <TableCell class=" text-ink-2">{{ task.order_no }}</TableCell>
                    <TableCell class="capitalize">{{ label(task.code) }}<p v-if="task.depends_on.length" class="text-dense text-ink-2 normal-case">after {{ task.depends_on.map(label).join(', ') }}</p></TableCell>
                    <TableCell class="text-ink-2">{{ label(task.owner_role) }}</TableCell>
                    <TableCell><StatusBadge :status="task.status" /></TableCell>
                    <TableCell class="max-w-xs text-ui text-ink-2">{{ task.summary }}</TableCell>
                    <TableCell class="text-right">
                        <span v-if="run.status === 'running' && (task.status === 'pending' || task.status === 'blocked')" class="flex items-center justify-end gap-2">
                            <Input v-model="notes[task.id]" placeholder="Note or reason" class="w-40" :aria-label="`Note for ${label(task.code)}`" />
                            <Button @click="execute(task.id)">Run</Button>
                            <Button variant="ghost" @click="skip(task.id)">Skip</Button>
                        </span>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </AppLayout>
</template>
