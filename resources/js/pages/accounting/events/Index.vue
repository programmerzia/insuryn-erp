<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { eventLabel } from '@/lib/events';
import { formatDate } from '@/lib/format';

/**
 * Gap fix GA-08 (design §8.4 exception queue): accounting events that did not post — failed, or queued longer than the configured minutes — with
 * why, the record they came from and Requeue for whoever holds accounting.requeue_event. Posted events appear in the recent list (tab).
 */
interface EventRow { id: string; event_type: string; status: string; transaction_date: string; queued_at: string; failure_reason: string | null; source: { url: string; label: string } | null }
interface PostedRow { id: string; event_type: string; status: string; transaction_date: string; source_label: string | null; journal_id: string | null; journal_number: string | null; via_api: boolean }
const props = defineProps<{ events: EventRow[]; recentPosted: PostedRow[]; staleAfterMinutes: number; can: { requeue: boolean } }>();

type Tab = 'attention' | 'recent';
const tab = ref<Tab>(props.events.length > 0 ? 'attention' : 'recent');
const active = ref<string | null>(null);
const requeueing = ref(false);

watch(tab, () => {
    active.value = null;
});

const attentionColumns: DataColumn<EventRow>[] = [
    { id: 'event', header: 'Event', value: (r) => eventLabel(r.event_type), width: 200 },
    { id: 'source', header: 'Record', value: (r) => r.source?.label ?? null, href: (r) => r.source?.url ?? undefined, width: 170 },
    { id: 'date', header: 'Date', type: 'date', value: (r) => r.transaction_date },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['failed', 'queued'] },
    { id: 'reason', header: 'Why it did not post', value: (r) => r.failure_reason ?? `Not posted after ${props.staleAfterMinutes} minutes`, width: 360, muted: true },
];
const postedColumns: DataColumn<PostedRow>[] = [
    { id: 'event', header: 'Event', value: (r) => eventLabel(r.event_type), width: 220 },
    { id: 'source', header: 'Source', value: (r) => r.source_label ?? '—', width: 200, muted: true },
    { id: 'date', header: 'Date', type: 'date', value: (r) => r.transaction_date },
    { id: 'api', header: 'Via API', value: (r) => (r.via_api ? 'Yes' : '—'), width: 80 },
    { id: 'journal', header: 'Journal', value: (r) => r.journal_number ?? '—', href: (r) => (r.journal_id ? `/accounting/journals/${r.journal_id}` : undefined), width: 140 },
];

const queueId = computed(() => (tab.value === 'attention' ? 'accounting-events' : 'accounting-events-recent'));
const queueTitle = computed(() => (tab.value === 'attention' ? 'Needs attention' : 'Recently posted'));
const queueColumns = computed(() => (tab.value === 'attention' ? attentionColumns : postedColumns));
const queueRows = computed(() => (tab.value === 'attention' ? props.events : props.recentPosted));
const emptyText = computed(() => (tab.value === 'attention'
    ? 'Nothing needs attention — every event posted.'
    : 'No posted events yet. Run erp:demo or post from Ledger API demo.'));
const emptyAction = computed(() => (tab.value === 'attention'
    ? { label: 'Open journals', href: '/accounting/journals' }
    : { label: 'Ledger API demo', href: '/accounting/ledger-api' }));

const tabClass = (value: Tab) => [
    'inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-ui font-medium transition-colors',
    tab.value === value ? 'bg-accent text-accent-ink' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
];

function requeue(row: EventRow): void {
    requeueing.value = true;
    router.post(`/accounting/events/${row.id}/requeue`, {}, { preserveScroll: true, onFinish: () => (requeueing.value = false), onSuccess: () => (active.value = null) });
}
</script>

<template>
    <AppLayout help="events" title="Accounting events" fill>
        <QueueView
            :id="queueId"
            :key="tab"
            v-model:active="active"
            :title="queueTitle"
            :columns="queueColumns"
            :rows="queueRows"
            :row-key="(r) => r.id"
            :url-sync="false"
            :empty-text="emptyText"
            :empty-action="emptyAction"
            :inspector-title="(r) => eventLabel(r.event_type)"
            :inspector-subtitle="(r) => ('source' in r && r.source?.label) ? r.source.label : ('journal_number' in r ? r.journal_number ?? undefined : undefined)"
            :primary-label="(r) => {
                if (tab === 'attention' && can.requeue && !requeueing) return r.status === 'failed' ? 'Requeue' : 'Send to posting again';
                if (tab === 'recent' && 'journal_id' in r && r.journal_id) return 'Open journal';
                return undefined;
            }"
            @primary="(r) => tab === 'attention' ? requeue(r as EventRow) : ('journal_id' in r && r.journal_id && router.visit(`/accounting/journals/${r.journal_id}`))"
        >
            <template #toolbar>
                <nav class="ml-3 flex shrink-0 items-center gap-1 rounded-control border border-line bg-surface-2 p-0.5" aria-label="Accounting events views">
                    <button type="button" :class="tabClass('attention')" @click="tab = 'attention'">
                        Needs attention
                        <span v-if="events.length" class="rounded-full bg-danger px-1.5 text-dense tabular-nums">{{ events.length }}</span>
                    </button>
                    <button type="button" :class="tabClass('recent')" @click="tab = 'recent'">Recently posted</button>
                </nav>
                <p class="ml-3 hidden min-w-0 truncate text-dense text-ink-2 xl:block">
                    <template v-if="tab === 'attention'">Failed or stuck events only — an empty list is healthy.</template>
                    <template v-else>Latest ledger events. API posts show <strong>Yes</strong> in Via API (seed: <code class="rounded-control bg-surface-2 px-1">erp:demo</code>).</template>
                </p>
            </template>
            <template v-if="tab === 'attention'" #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Date', value: formatDate(row.transaction_date) }, { label: 'Queued', value: row.queued_at }, { label: 'Why', value: row.failure_reason ?? `Not posted after ${staleAfterMinutes} minutes: the posting worker may be stopped.` }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <p v-if="row.source" class="mt-3 text-ui"><Link :href="row.source.url" class="text-accent-text hover:underline">Open {{ row.source.label }}</Link></p>
                <p class="mt-4 border-t border-line pt-3 text-ui text-ink-2">
                    {{ can.requeue ? 'Fix the cause first — map the account role, open the period — then requeue. If it fails again, the new reason shows here.' : 'A finance manager can requeue it once the cause is fixed.' }}
                </p>
            </template>
            <template v-else #details="{ row }">
                <DetailList :items="[{ label: 'Event', value: eventLabel(row.event_type) }, { label: 'Date', value: formatDate(row.transaction_date) }, { label: 'Source', value: row.source_label ?? '—' }, { label: 'Via API', value: row.via_api ? 'Yes' : 'No' }, { label: 'Journal', value: row.journal_number ?? '—' }]" />
                <p v-if="row.journal_id" class="mt-3 text-ui"><Link :href="`/accounting/journals/${row.journal_id}`" class="text-accent-text hover:underline">Open journal {{ row.journal_number }}</Link></p>
            </template>
        </QueueView>
    </AppLayout>
</template>
