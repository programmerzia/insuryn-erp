<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    report: string;
    title: string;
    filter: 'range' | 'as_of' | 'range_by';
    filters: { from: string; to: string; as_of: string; by: string };
    columns: { key: string; label: string; align: 'left' | 'right' }[];
    rows: { cells: Record<string, string | number | null>; link: string | null }[];
    totals: Record<string, string>;
}>();

const filters = reactive({ ...props.filters });

function apply(): void {
    const params = new URLSearchParams(window.location.search);
    const query: Record<string, string> = Object.fromEntries(params.entries());
    if (props.filter === 'as_of') {
        query.as_of = filters.as_of;
    } else {
        query.from = filters.from;
        query.to = filters.to;
        if (props.filter === 'range_by') query.by = filters.by;
    }
    router.get(`/reports/${props.report}`, query, { preserveState: true });
}
</script>

<template>
    <AppLayout :title="title">
        <PageHeader eyebrow="Report" :title="title">
            <form class="flex flex-wrap items-center gap-2" @submit.prevent="apply">
                <Input v-if="filter === 'as_of'" v-model="filters.as_of" type="date" class="w-40" aria-label="As of" />
                <template v-else><Input v-model="filters.from" type="date" class="w-40" aria-label="From" /><Input v-model="filters.to" type="date" class="w-40" aria-label="To" /></template>
                <SelectInput v-if="filter === 'range_by'" v-model="filters.by" :options="['product', 'branch', 'agent'].map((b) => ({ value: b, label: `by ${b}` }))" class="w-36" aria-label="Group by" />
                <Button type="submit" variant="ghost">Show</Button>
            </form>
            <Link href="/reports" class="text-ui text-accent-text hover:underline">All reports</Link>
        </PageHeader>
        <Table>
            <TableHeader><TableRow><TableHead v-for="column in columns" :key="column.key" :class="column.align === 'right' ? 'text-right' : ''">{{ column.label }}</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="(row, index) in rows" :key="index">
                    <TableCell v-for="(column, position) in columns" :key="column.key" :class="column.align === 'right' ? 'text-right tabular-nums' : ''">
                        <Link v-if="position === 0 && row.link" :href="row.link" class="text-accent-text hover:underline">{{ row.cells[column.key] }}</Link>
                        <template v-else>{{ row.cells[column.key] }}</template>
                    </TableCell>
                </TableRow>
                <TableEmpty v-if="rows.length === 0" :colspan="columns.length">Nothing to report for these dates.</TableEmpty>
            </TableBody>
        </Table>
        <dl v-if="Object.keys(totals).length" class="mt-4 flex flex-wrap justify-end gap-x-8 gap-y-2 text-ui">
            <div v-for="(value, key) in totals" :key="key" class="text-right">
                <dt class="text-dense text-ink-2">{{ String(key).replace(/_/g, ' ') }}</dt>
                <dd class=" tabular-nums">{{ value }}</dd>
            </div>
        </dl>
    </AppLayout>
</template>
