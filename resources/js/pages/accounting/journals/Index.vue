<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import Inspector from '@/components/shell/Inspector.vue';
import PinLink from '@/components/shell/PinLink.vue';
import SplitPane from '@/components/shell/SplitPane.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { useStatusBar } from '@/lib/statusbar';
import type { EntityRef, JournalListItem } from '@/types/accounting';

const props = defineProps<{
    entity: EntityRef;
    filters: { status: string | null };
    statuses: string[];
    journals: { data: JournalListItem[]; currentPage: number; lastPage: number; total: number };
}>();

const selectedId = ref<string | null>(null);
const selected = computed(() => props.journals.data.find((journal) => journal.id === selectedId.value) ?? null);
const bar = useStatusBar();

function visit(params: Record<string, unknown>): void {
    router.get('/accounting/journals', { status: props.filters.status ?? undefined, entity_id: props.entity.id, ...params }, { preserveState: true, preserveScroll: true });
}

watchEffect(() => {
    bar.rows = props.journals.total;
    bar.page = { current: props.journals.currentPage, last: props.journals.lastPage, go: (page) => visit({ page }) };
});
</script>

<template>
    <AppLayout title="Journals" fill>
        <SplitPane id="journals" :open="selected !== null">
            <div class="flex items-center gap-3 border-b border-line px-4 py-2">
                <h1 class="text-title font-semibold">Journals</h1>
                <label class="ml-4 flex items-center gap-2 text-ui text-ink-2" for="status-filter">
                    Status
                    <select id="status-filter" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body text-ink" :value="filters.status ?? ''" @change="visit({ status: ($event.target as HTMLSelectElement).value || undefined, page: undefined })">
                        <option value="">All</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
                    </select>
                </label>
                <Link href="/accounting/journals/create" class="ml-auto inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">New manual journal</Link>
            </div>
            <div class="min-h-0 flex-1 overflow-auto">
                <Table class="border-0">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead>Number</TableHead>
                            <TableHead>Posting date</TableHead>
                            <TableHead>Kind</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Total ({{ entity.currency }})</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="journal in journals.data"
                            :key="journal.id"
                            class="cursor-default"
                            :class="{ 'bg-accent-soft hover:bg-accent-soft': journal.id === selectedId }"
                            :aria-selected="journal.id === selectedId"
                            @click="selectedId = journal.id"
                        >
                            <TableCell>
                                <PinLink :href="`/accounting/journals/${journal.id}`" :title="journal.number ?? 'Draft journal'" class="text-accent-text hover:underline" @click.stop>{{ journal.number ?? 'Draft' }}</PinLink>
                            </TableCell>
                            <TableCell class="num text-left text-ink-2">{{ journal.postingDate }}</TableCell>
                            <TableCell>{{ journal.kind }}</TableCell>
                            <TableCell class="max-w-72 truncate text-ink-2">{{ journal.description }}</TableCell>
                            <TableCell><StatusBadge :status="journal.status" /></TableCell>
                            <TableCell class="num">{{ journal.total }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="journals.data.length === 0" :colspan="6">No journals match this filter.</TableEmpty>
                    </TableBody>
                </Table>
            </div>
            <template #inspector>
                <Inspector v-if="selected" :title="selected.number ?? 'Draft journal'" :subtitle="selected.description ?? undefined" @close="selectedId = null">
                    <template #details>
                        <dl class="grid grid-cols-[8rem_1fr] gap-y-2 text-ui">
                            <dt class="text-ink-2">Status</dt><dd><StatusBadge :status="selected.status" /></dd>
                            <dt class="text-ink-2">Posting date</dt><dd class="num text-left">{{ selected.postingDate }}</dd>
                            <dt class="text-ink-2">Kind</dt><dd>{{ selected.kind }}</dd>
                            <dt class="text-ink-2">Total</dt><dd class="num text-left">{{ selected.total }} {{ selected.currency }}</dd>
                        </dl>
                        <Link :href="`/accounting/journals/${selected.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the journal</Link>
                    </template>
                </Inspector>
            </template>
        </SplitPane>
    </AppLayout>
</template>
