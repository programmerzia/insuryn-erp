<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { eventLabel } from '@/lib/events';
import { formatDate } from '@/lib/format';

/**
 * Gap fix GA-08 (design §8.4 exception queue): accounting events that did not post — failed, or queued longer than the configured minutes — with
 * why, the record they came from and Requeue for whoever holds accounting.requeue_event. Fix the cause first (map the account role, open the period).
 */
interface EventRow { id: string; event_type: string; status: string; transaction_date: string; queued_at: string; failure_reason: string | null; source: { url: string; label: string } | null }
const props = defineProps<{ events: EventRow[]; staleAfterMinutes: number; can: { requeue: boolean } }>();

const active = ref<string | null>(null);
const requeueing = ref(false);
const columns: DataColumn<EventRow>[] = [
    { id: 'event', header: 'Event', value: (r) => eventLabel(r.event_type), width: 200 },
    { id: 'source', header: 'Record', value: (r) => r.source?.label ?? null, href: (r) => r.source?.url ?? undefined, width: 170 },
    { id: 'date', header: 'Date', type: 'date', value: (r) => r.transaction_date },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['failed', 'queued'] },
    { id: 'reason', header: 'Why it did not post', value: (r) => r.failure_reason ?? `Not posted after ${props.staleAfterMinutes} minutes`, width: 360, muted: true },
];

function requeue(row: EventRow): void {
    requeueing.value = true;
    router.post(`/accounting/events/${row.id}/requeue`, {}, { preserveScroll: true, onFinish: () => (requeueing.value = false), onSuccess: () => (active.value = null) });
}
</script>

<template>
    <AppLayout help="accounting" title="Accounting events" fill>
        <QueueView
            id="accounting-events"
            v-model:active="active"
            title="Accounting events"
            :columns="columns"
            :rows="events"
            :row-key="(r) => r.id"
            :url-sync="false"
            empty-text="Every accounting event posted."
            :empty-action="{ label: 'Open journals', href: '/accounting/journals' }"
            :inspector-title="(r) => eventLabel(r.event_type)"
            :inspector-subtitle="(r) => r.source?.label"
            :primary-label="(r) => (can.requeue && !requeueing ? (r.status === 'failed' ? 'Requeue' : 'Send to posting again') : undefined)"
            @primary="requeue"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Date', value: formatDate(row.transaction_date) }, { label: 'Queued', value: row.queued_at }, { label: 'Why', value: row.failure_reason ?? `Not posted after ${staleAfterMinutes} minutes: the posting worker may be stopped.` }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <p v-if="row.source" class="mt-3 text-ui"><Link :href="row.source.url" class="text-accent-text hover:underline">Open {{ row.source.label }}</Link></p>
                <p class="mt-4 border-t border-line pt-3 text-ui text-ink-2">
                    {{ can.requeue ? 'Fix the cause first — map the account role, open the period — then requeue. If it fails again, the new reason shows here.' : 'A finance manager can requeue it once the cause is fixed.' }}
                </p>
            </template>
        </QueueView>
    </AppLayout>
</template>
