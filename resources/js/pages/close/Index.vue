<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';

interface Period { id: string; label: string; starts: string; ends: string; status: string; run: { id: string; status: string } | null }
const props = defineProps<{ periods: Period[]; can: { start: boolean; reopen: boolean } }>();

const active = ref<string | null>(null);
const reason = ref('');
const month = (p: Period) => new Date(`${p.starts}T00:00:00`).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
const canStart = (p: Period) => props.can.start && p.status !== 'locked' && (!p.run || p.run.status === 'reopened');
const closeState = (p: Period) => (p.run ? p.run.status : 'not_started');
const columns: DataColumn<Period>[] = [
    { id: 'month', header: 'Month', value: (p) => month(p), width: 110 },
    { id: 'fiscal', header: 'Fiscal period', value: (p) => p.label, width: 110, muted: true },
    { id: 'starts', header: 'Starts', type: 'date', value: (p) => p.starts },
    { id: 'ends', header: 'Ends', type: 'date', value: (p) => p.ends },
    { id: 'status', header: 'Period', type: 'status', value: (p) => p.status, filterOptions: ['open', 'soft_locked', 'locked'] },
    { id: 'close', header: 'Close', type: 'status', value: (p) => closeState(p), filterOptions: ['not_started', 'running', 'completed', 'reopened'] },
];

function start(p: Period): void {
    router.post(`/close/periods/${p.id}`, {}, { preserveScroll: true });
}
async function reopen(p: Period): Promise<void> {
    const ok = await confirmAction({ title: `Reopen ${month(p)}?`, body: 'Postings into the month become possible again, and its close starts over. The reason is kept in the audit trail.', confirmLabel: `Reopen ${month(p)}`, tone: 'danger' });
    if (ok) router.post(`/close/periods/${p.id}/reopen`, { reason: reason.value }, { preserveScroll: true, onSuccess: () => (reason.value = '') });
}
</script>

<template>
    <AppLayout title="Month-end close" fill>
        <QueueView
            id="close-periods"
            v-model:active="active"
            title="Month-end close"
            :columns="columns"
            :rows="periods"
            :row-key="(p) => p.id"
            :url-sync="false"
            empty-text="No fiscal periods are set up."
            :inspector-title="(p) => month(p)"
            :inspector-subtitle="(p) => `${formatDate(p.starts)} to ${formatDate(p.ends)}`"
            :primary-label="(p) => (p.run && p.run.status !== 'reopened' ? 'Open the checklist' : canStart(p) ? 'Start close' : undefined)"
            @primary="(p) => (p.run && p.run.status !== 'reopened' ? router.visit(`/close/runs/${p.run.id}`) : start(p))"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Period' }, { label: 'Close' }]">
                    <template #Period><StatusBadge :status="row.status" /></template>
                    <template #Close><StatusBadge :status="closeState(row)" /></template>
                </DetailList>
                <Link v-if="row.run" :href="`/close/runs/${row.run.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the close checklist</Link>
                <div v-if="can.reopen && row.status !== 'open'" class="mt-4 grid gap-2 border-t border-line pt-4">
                    <label class="grid gap-1 text-ui font-medium" for="reopen-reason">Reason to reopen<input id="reopen-reason" v-model="reason" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body font-normal" /></label>
                    <button type="button" class="h-8 justify-self-start rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2 disabled:opacity-50" :disabled="reason.trim() === ''" @click="reopen(row)">Reopen the period</button>
                </div>
            </template>
        </QueueView>
    </AppLayout>
</template>
