<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { EntityRef, JournalListItem } from '@/types/accounting';

const props = defineProps<{
    entity: EntityRef;
    filters: { status: string | null };
    statuses: string[];
    journals: { data: JournalListItem[]; currentPage: number; lastPage: number; total: number };
}>();

function filter(status: string): void {
    router.get('/accounting/journals', { status: status === '' ? undefined : status, entity_id: props.entity.id }, { preserveState: true });
}

function goToPage(page: number): void {
    router.get('/accounting/journals', { page, status: props.filters.status ?? undefined, entity_id: props.entity.id }, { preserveState: true });
}
</script>

<template>
    <AppLayout title="Journals">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-ui font-medium text-ink-2">{{ entity.code }} · {{ journals.total }} journals</p>
                <h1 class="mt-1 text-title font-semibold">Journals</h1>
                <Link href="/accounting/journals/create" class="mt-2 inline-block text-ui text-accent-text hover:underline">New manual journal</Link>
            </div>
            <label class="grid gap-1 text-dense text-ink-2" for="status-filter">
                Status
                <select
                    id="status-filter"
                    class="rounded-control border border-line-control bg-surface px-2 py-1.5 text-ui text-ink"
                    :value="filters.status ?? ''"
                    @change="filter(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">All</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
                </select>
            </label>
        </div>

        <Table>
            <TableHeader>
                <TableRow class="hover:bg-transparent">
                    <TableHead>Number</TableHead>
                    <TableHead>Posting date</TableHead>
                    <TableHead>Kind</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead class="text-right">Total</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="journal in journals.data" :key="journal.id">
                    <TableCell class="">
                        <Link :href="`/accounting/journals/${journal.id}`" class="text-accent-text hover:underline">{{ journal.number ?? 'unnumbered draft' }}</Link>
                    </TableCell>
                    <TableCell class=" tabular-nums text-ink-2">{{ journal.postingDate }}</TableCell>
                    <TableCell>{{ journal.kind }}</TableCell>
                    <TableCell class="max-w-72 truncate text-ink-2">{{ journal.description }}</TableCell>
                    <TableCell><StatusBadge :status="journal.status" /></TableCell>
                    <TableCell class="text-right tabular-nums">{{ journal.total }}</TableCell>
                </TableRow>
                <TableEmpty v-if="journals.data.length === 0" :colspan="6">No journals match this filter.</TableEmpty>
            </TableBody>
        </Table>

        <nav v-if="journals.lastPage > 1" class="mt-4 flex items-center justify-end gap-3 text-ui" aria-label="Pages">
            <button type="button" class="text-accent-text disabled:text-ink-2" :disabled="journals.currentPage <= 1" @click="goToPage(journals.currentPage - 1)">Previous</button>
            <span class="text-ink-2">Page {{ journals.currentPage }} of {{ journals.lastPage }}</span>
            <button type="button" class="text-accent-text disabled:text-ink-2" :disabled="journals.currentPage >= journals.lastPage" @click="goToPage(journals.currentPage + 1)">Next</button>
        </nav>
    </AppLayout>
</template>
