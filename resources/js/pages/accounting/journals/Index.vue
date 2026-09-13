<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Inspector from '@/components/shell/Inspector.vue';
import SplitPane from '@/components/shell/SplitPane.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { type DataColumn, DataTable } from '@/components/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';
import type { EntityRef, JournalListItem } from '@/types/accounting';

const props = defineProps<{
    entity: EntityRef;
    filters: { status: string | null };
    statuses: string[];
    journals: { data: JournalListItem[]; currentPage: number; lastPage: number; total: number };
}>();

const active = ref<string | null>(null);
const selected = computed(() => props.journals.data.find((journal) => journal.id === active.value) ?? null);

const columns: DataColumn<JournalListItem>[] = [
    { id: 'number', header: 'Number', value: (j) => j.number ?? 'Draft', href: (j) => `/accounting/journals/${j.id}`, width: 150 },
    { id: 'postingDate', header: 'Posting date', type: 'date', value: (j) => j.postingDate },
    { id: 'kind', header: 'Kind', value: (j) => j.kind, width: 96, filterOptions: ['system', 'manual', 'adjustment', 'reversal', 'opening'] },
    { id: 'description', header: 'Description', value: (j) => (j.description && /^[A-Z_]+$/.test(j.description) ? eventLabel(j.description) : j.description), width: 320, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (j) => j.status, filterOptions: props.statuses },
    { id: 'total', header: 'Total', type: 'money', value: (j) => j.total, width: 140 },
];

function visit(params: Record<string, unknown>): void {
    router.get('/accounting/journals', { status: props.filters.status ?? undefined, entity_id: props.entity.id, ...params }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Journals" fill>
        <SplitPane id="journals" :open="selected !== null">
            <DataTable
                id="journals"
                v-model:active="active"
                label="Journals"
                :columns="columns"
                :rows="journals.data"
                :row-key="(j) => j.id"
                :currency="entity.currency"
                :page="{ current: journals.currentPage, last: journals.lastPage, total: journals.total, go: (page) => visit({ page }) }"
                empty-text="No journals yet. Postings from policies, receipts and claims appear here."
                export-name="journals"
                @close="active = null"
            >
                <template #toolbar>
                    <h1 class="mr-3 text-section font-semibold">Journals</h1>
                    <Link href="/accounting/journals/create" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">New manual journal</Link>
                </template>
            </DataTable>
            <template #inspector>
                <Inspector v-if="selected" :title="selected.number ?? 'Draft journal'" :subtitle="selected.description ?? undefined" @close="active = null">
                    <template #details>
                        <dl class="grid grid-cols-[8rem_1fr] gap-y-2 text-ui">
                            <dt class="text-ink-2">Status</dt><dd><StatusBadge :status="selected.status" /></dd>
                            <dt class="text-ink-2">Posting date</dt><dd>{{ formatDate(selected.postingDate) }}</dd>
                            <dt class="text-ink-2">Kind</dt><dd>{{ selected.kind }}</dd>
                            <dt class="text-ink-2">Total</dt><dd class="num text-left">{{ formatMoney(selected.total) }} {{ selected.currency }}</dd>
                        </dl>
                        <Link :href="`/accounting/journals/${selected.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the journal</Link>
                    </template>
                </Inspector>
            </template>
        </SplitPane>
    </AppLayout>
</template>
