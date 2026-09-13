<script setup lang="ts" generic="T">
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuSeparator, ContextMenuTrigger } from 'reka-ui';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import PinLink from '@/components/shell/PinLink.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTableToolbar from '@/components/table/DataTableToolbar.vue';
import type { DataColumn, ServerPage } from '@/components/table/types';
import { useDataTable } from '@/components/table/useDataTable';
import Kbd from '@/components/ui/Kbd.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney } from '@/lib/money';
import { savePreference, usePreferences } from '@/lib/preferences';
import { useReloading } from '@/lib/loading';
import { useShortcut } from '@/lib/shortcuts';
import { pinTab } from '@/lib/tabs';
import { toast } from '@/lib/toasts';

/**
 * The queue table every list shares (UX brief §4, §6.1): TanStack Table with virtual scrolling above 200 rows, sticky header, column
 * resize/reorder/hide, saved views per user, inline filter row, multi-sort (Shift+click), footer totals, selection sum in the status bar,
 * bulk-action toolbar, URL-synced filters, right-click menu, keyboard: ↑↓ move · Enter open · Space select · Shift+↑↓ range · / filter · Esc.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        columns: DataColumn<T>[];
        rows: T[];
        rowKey: (row: T) => string;
        currency?: string;
        page?: ServerPage;
        selectable?: boolean;
        loading?: boolean;
        emptyText?: string;
        urlSync?: boolean;
        exportName?: string;
        label: string;
        /** false: a click only makes the row active; Enter opens it (screens where opening acts, like accepting a match). */
        openOnClick?: boolean;
        /** Icon-only toolbar with tooltips, for tables sharing the screen. */
        compactToolbar?: boolean;
        /** Empty state's one primary action (brief §4 "one sentence + one primary action"). */
        emptyAction?: { label: string; href: string } | null;
    }>(),
    { currency: undefined, page: undefined, selectable: false, loading: false, emptyText: 'Nothing to show.', urlSync: true, exportName: undefined, openOnClick: true, compactToolbar: false, emptyAction: null },
);
const emit = defineEmits<{ open: [row: T]; close: [] }>();
const active = defineModel<string | null>('active', { default: null });

const state = useDataTable<T>({
    id: props.id,
    columns: () => props.columns,
    rows: () => props.rows,
    rowKey: props.rowKey,
    page: () => props.page,
    urlSync: props.urlSync,
    selectable: () => props.selectable,
});
const { table, rows, columns, totals, selectedRows, showFilters, activeIndex } = state;

const preferences = usePreferences();
const reloading = useReloading();
const showSkeleton = computed(() => props.loading || reloading.value);
const scroller = ref<HTMLElement | null>(null);
const filterRow = ref<HTMLElement | null>(null);
const rowHeight = computed(() => (preferences.density === 'comfortable' ? 40 : 32));
const virtual = computed(() => rows.value.length > 200);
const virtualizer = useVirtualizer(computed(() => ({
    count: rows.value.length,
    getScrollElement: () => scroller.value,
    estimateSize: () => rowHeight.value,
    overscan: 12,
    enabled: virtual.value,
})));
const windowRows = computed(() => {
    if (!virtual.value) return rows.value.map((row, index) => ({ row, index }));
    return virtualizer.value.getVirtualItems().map((item) => ({ row: rows.value[item.index]!, index: item.index }));
});
const padTop = computed(() => (virtual.value ? (virtualizer.value.getVirtualItems()[0]?.start ?? 0) : 0));
const padBottom = computed(() => {
    if (!virtual.value) return 0;
    const items = virtualizer.value.getVirtualItems();
    return virtualizer.value.getTotalSize() - (items[items.length - 1]?.end ?? 0);
});
const tableWidth = computed(() => columns.value.reduce((sum, c) => sum + c.column.getSize(), props.selectable ? 36 : 0));
const selectedSum = computed(() => {
    const column = props.columns.find((c) => c.total);
    return column ? formatMinor(selectedRows.value.reduce((sum, row) => sum + (parseMoney(String(column.value(row) ?? '')) ?? 0n), 0n)) : null;
});
const hasTotals = computed(() => props.columns.some((c) => c.total));
const activeFilters = computed(() => state.columnFilters.value.length);

watch(active, (key) => {
    const index = rows.value.findIndex((r) => r.id === key);
    if (index !== -1) activeIndex.value = index;
});

function headerLabel(meta: DataColumn<T>): string {
    return meta.type === 'money' && props.currency ? `${meta.header} (${props.currency})` : meta.header;
}

function display(meta: DataColumn<T>, row: T): string {
    const value = meta.value(row);
    if (value === null || value === undefined) return '';
    if (meta.type === 'money') return formatMoney(String(value));
    if (meta.type === 'date') return formatDate(String(value));
    return String(value);
}

function focusGrid(): void {
    scroller.value?.querySelector<HTMLElement>('table[role=grid]')?.focus();
}

function focusRow(index: number): void {
    if (rows.value.length === 0) return;
    activeIndex.value = Math.max(0, Math.min(index, rows.value.length - 1));
    if (virtual.value) virtualizer.value.scrollToIndex(activeIndex.value, { align: 'auto' });
    else void nextTick(() => scroller.value?.querySelector(`[data-row-index="${activeIndex.value}"]`)?.scrollIntoView({ block: 'nearest' }));
}

function open(index: number): void {
    const row = rows.value[index];
    if (!row) return;
    activeIndex.value = index;
    active.value = row.id;
    emit('open', row.original);
}

function onKeydown(event: KeyboardEvent): void {
    if ((event.target as HTMLElement).closest('input, select, button, a, textarea')) return;
    const index = activeIndex.value;
    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp': {
            event.preventDefault();
            const next = index === -1 ? 0 : index + (event.key === 'ArrowDown' ? 1 : -1);
            if (event.shiftKey && props.selectable) state.extendTo(Math.max(0, Math.min(next, rows.value.length - 1)));
            else state.anchorIndex.value = -1;
            focusRow(next);
            break;
        }
        case 'Enter':
            if (event.ctrlKey || event.metaKey) return;
            event.preventDefault();
            open(index === -1 ? 0 : index);
            break;
        case ' ':
            if (!props.selectable) return;
            event.preventDefault();
            state.toggleRow(index === -1 ? 0 : index);
            break;
        case 'Escape':
            if (active.value !== null) {
                active.value = null;
                emit('close');
            } else if (selectedRows.value.length) state.clearSelection();
            break;
        case 'Home':
            event.preventDefault();
            focusRow(0);
            break;
        case 'End':
            event.preventDefault();
            focusRow(rows.value.length - 1);
            break;
    }
}

useShortcut('table.filter', () => {
    showFilters.value = true;
    void nextTick(() => filterRow.value?.querySelector<HTMLInputElement>('input, select')?.focus());
});

// Header drag to reorder
const dragging = ref<string | null>(null);

function exportCsv(): void {
    const visible = columns.value.map((c) => c.meta);
    const escape = (text: string) => (/[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text);
    const lines = [visible.map((m) => escape(headerLabel(m))).join(',')];
    for (const row of rows.value) {
        lines.push(visible.map((m) => escape(String(m.value(row.original) ?? ''))).join(','));
    }
    const url = URL.createObjectURL(new Blob([`${lines.join('\n')}\n`], { type: 'text/csv;charset=utf-8' }));
    const link = Object.assign(document.createElement('a'), { href: url, download: `${props.exportName ?? props.id}.csv` });
    link.click();
    URL.revokeObjectURL(url);
    toast(`Exported ${rows.value.length} rows.`);
}

const contextRow = ref<number>(-1);
const contextHref = computed(() => {
    const row = rows.value[contextRow.value];
    const column = props.columns.find((c) => c.href);
    return row && column?.href ? (column.href(row.original) ?? null) : null;
});
function pinContext(): void {
    const row = rows.value[contextRow.value];
    const column = props.columns.find((c) => c.href);
    if (!row || !column || !contextHref.value) return;
    const result = pinTab(preferences.tabs, { href: contextHref.value, title: column.pinTitle?.(row.original) ?? String(column.value(row.original) ?? '') });
    if (!result.ok) return toast('Eight tabs are pinned. Close one first.');
    savePreference('tabs', result.tabs, 0);
    router.visit(contextHref.value);
}
function copyContext(): void {
    const row = rows.value[contextRow.value];
    const first = columns.value[0]?.meta;
    if (row && first) void navigator.clipboard?.writeText(String(first.value(row.original) ?? '')).then(() => toast('Copied.'));
}

defineExpose({ state, focusRow });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <DataTableToolbar
            :views="state.views.value"
            :columns="table.getAllLeafColumns().map((c) => ({ id: c.id, header: (c.columnDef.meta as DataColumn<T>).header, visible: c.getIsVisible(), hideable: c.getCanHide() }))"
            :filters-shown="showFilters"
            :active-filters="activeFilters"
            :selected="selectedRows.length"
            :selected-sum="selectedSum"
            :compact="compactToolbar"
            @toggle-filters="showFilters = !showFilters"
            @toggle-column="(id, visible) => table.getColumn(id)?.toggleVisibility(visible)"
            @reset-columns="state.resetLayout()"
            @apply-view="state.applyView"
            @save-view="state.saveView"
            @delete-view="state.deleteView"
            @export-csv="exportCsv"
            @clear-selection="state.clearSelection()"
        >
            <template #start><slot name="toolbar" /></template>
            <template #bulk><slot name="bulk" :rows="selectedRows" :clear="state.clearSelection" /></template>
        </DataTableToolbar>

        <ContextMenuRoot>
            <ContextMenuTrigger as-child>
                <div ref="scroller" class="relative min-h-0 flex-1 overflow-auto">
                    <table
                        class="table-fixed border-separate border-spacing-0 text-dense outline-none"
                        :class="activeIndex === -1 ? 'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus' : ''"
                        :style="{ width: `max(${tableWidth}px, 100%)` }"
                        tabindex="0"
                        role="grid"
                        :aria-label="label"
                        :aria-rowcount="rows.length + 1"
                        :aria-multiselectable="selectable || undefined"
                        :aria-activedescendant="activeIndex >= 0 && rows[activeIndex] ? `${id}-row-${activeIndex}` : undefined"
                        @keydown="onKeydown"
                    >
                        <colgroup>
                            <col v-if="selectable" style="width: 36px" />
                            <col v-for="{ column } in columns" :key="column.id" :style="{ width: `${column.getSize()}px` }" />
                            <col />
                        </colgroup>
                        <thead class="sticky top-0 z-10 bg-surface-2">
                            <tr>
                                <th v-if="selectable" class="h-(--row-h) border-b border-line px-2 text-left">
                                    <input type="checkbox" class="size-3.5 accent-accent align-middle" :checked="table.getIsAllRowsSelected()" :indeterminate="table.getIsSomeRowsSelected()" aria-label="Select all rows" @change="table.toggleAllRowsSelected()" />
                                </th>
                                <th
                                    v-for="{ column, meta } in columns"
                                    :key="column.id"
                                    class="group relative h-(--row-h) border-b border-line px-3 font-medium whitespace-nowrap text-ink-2"
                                    :class="[meta.type === 'money' || meta.type === 'number' ? 'text-right' : 'text-left', dragging && dragging !== column.id ? 'cursor-copy' : '']"
                                    :aria-sort="column.getIsSorted() === 'asc' ? 'ascending' : column.getIsSorted() === 'desc' ? 'descending' : 'none'"
                                    draggable="true"
                                    @dragstart="dragging = column.id"
                                    @dragover.prevent
                                    @drop="dragging && state.moveColumn(dragging, column.id); dragging = null"
                                    @dragend="dragging = null"
                                >
                                    <button type="button" class="inline-flex max-w-full items-center gap-1 hover:text-ink" :title="'Sort (Shift+click adds a second sort)'" @click="column.toggleSorting(undefined, $event.shiftKey)">
                                        <span class="truncate">{{ headerLabel(meta) }}</span>
                                        <ArrowUp v-if="column.getIsSorted() === 'asc'" :size="12" :stroke-width="1.5" aria-hidden="true" />
                                        <ArrowDown v-else-if="column.getIsSorted() === 'desc'" :size="12" :stroke-width="1.5" aria-hidden="true" />
                                        <span v-if="state.sorting.value.length > 1 && column.getSortIndex() >= 0" class="num">{{ column.getSortIndex() + 1 }}</span>
                                    </button>
                                    <span
                                        class="absolute top-1 right-0 bottom-1 w-1.5 cursor-col-resize border-r border-line opacity-0 group-hover:opacity-100 hover:border-focus"
                                        :class="{ 'border-focus opacity-100': column.getIsResizing() }"
                                        role="separator"
                                        :aria-label="`Resize ${meta.header}`"
                                        @mousedown.stop="column.getCanResize() && table.getHeaderGroups()[0]?.headers.find((h) => h.column.id === column.id)?.getResizeHandler()($event)"
                                        @touchstart.stop="table.getHeaderGroups()[0]?.headers.find((h) => h.column.id === column.id)?.getResizeHandler()($event)"
                                        @dblclick="column.resetSize()"
                                    />
                                </th>
                                <th class="border-b border-line" aria-hidden="true" />
                            </tr>
                            <tr v-if="showFilters" ref="filterRow">
                                <th v-if="selectable" class="border-b border-line" />
                                <th v-for="{ column, meta } in columns" :key="column.id" class="border-b border-line px-1.5 py-1 font-normal">
                                    <select
                                        v-if="meta.filterOptions"
                                        class="h-7 w-full rounded-control border border-line-control bg-surface px-1 text-dense text-ink"
                                        :value="state.filterValue(column.id)"
                                        :aria-label="`Filter ${meta.header}`"
                                        @change="state.setFilter(column.id, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">Any</option>
                                        <option v-for="option in meta.filterOptions" :key="option" :value="option">{{ option.replaceAll('_', ' ') }}</option>
                                    </select>
                                    <input
                                        v-else
                                        class="h-7 w-full rounded-control border border-line-control bg-surface px-1.5 text-dense text-ink placeholder:text-ink-2"
                                        :class="{ 'text-right': meta.type === 'money' || meta.type === 'number' }"
                                        :value="state.filterValue(column.id)"
                                        :placeholder="meta.type === 'money' ? 'e.g. >1000' : meta.type === 'date' ? 'e.g. Sep 2026' : 'Contains'"
                                        :aria-label="`Filter ${meta.header}`"
                                        @input="state.setFilter(column.id, ($event.target as HTMLInputElement).value)"
                                        @keydown.escape.stop="state.setFilter(column.id, ''); focusGrid()"
                                        @keydown.down.prevent="focusGrid(); focusRow(0)"
                                    />
                                </th>
                                <th class="border-b border-line" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="padTop > 0" aria-hidden="true"><td :style="{ height: `${padTop}px` }" /></tr>
                            <template v-if="showSkeleton">
                                <tr v-for="n in 8" :key="`skeleton-${n}`" class="h-(--row-h)" aria-hidden="true">
                                    <td v-if="selectable" class="border-b border-line" />
                                    <td v-for="{ column } in columns" :key="column.id" class="border-b border-line px-3"><span class="block h-2.5 w-2/3 animate-pulse rounded-control bg-surface-2" /></td>
                                    <td class="border-b border-line" />
                                </tr>
                            </template>
                            <tr
                                v-for="{ row, index } in windowRows"
                                v-else
                                :key="row.id"
                                :id="`${id}-row-${index}`"
                                :data-row-index="index"
                                class="h-(--row-h) cursor-default"
                                :class="[
                                    row.id === active ? 'bg-accent-soft' : row.getIsSelected() ? 'bg-accent-soft' : 'hover:bg-surface-2',
                                    index === activeIndex ? 'outline-2 -outline-offset-2 outline-focus' : '',
                                ]"
                                :aria-selected="selectable ? row.getIsSelected() : row.id === active"
                                :aria-rowindex="index + 2"
                                @click="openOnClick ? open(index) : ((activeIndex = index), (active = row.id))"
                                @contextmenu="contextRow = index; activeIndex = index"
                            >
                                <td v-if="selectable" class="border-b border-line px-2" @click.stop>
                                    <input type="checkbox" class="size-3.5 accent-accent align-middle" :checked="row.getIsSelected()" :aria-label="`Select row ${index + 1}`" @change="state.toggleRow(index)" />
                                </td>
                                <td
                                    v-for="{ column, meta } in columns"
                                    :key="column.id"
                                    class="overflow-hidden border-b border-line px-3 text-ellipsis whitespace-nowrap"
                                    :class="[meta.type === 'money' || meta.type === 'number' ? 'num' : '', meta.muted ? 'text-ink-2' : 'text-ink']"
                                >
                                    <slot :name="`cell-${column.id}`" :row="row.original" :value="meta.value(row.original)">
                                        <StatusBadge v-if="meta.type === 'status' && meta.value(row.original)" :status="String(meta.value(row.original))" />
                                        <PinLink v-else-if="meta.href && meta.href(row.original)" :href="meta.href(row.original)!" :title="meta.pinTitle?.(row.original) ?? display(meta, row.original)" class="text-accent-text hover:underline" @click.stop>
                                            {{ display(meta, row.original) }}
                                        </PinLink>
                                        <template v-else>{{ display(meta, row.original) }}</template>
                                    </slot>
                                </td>
                                <td class="border-b border-line" />
                            </tr>
                            <tr v-if="padBottom > 0" aria-hidden="true"><td :style="{ height: `${padBottom}px` }" /></tr>
                            <tr v-if="!showSkeleton && rows.length === 0">
                                <td :colspan="columns.length + (selectable ? 2 : 1)" class="px-3 py-10 text-center text-ui text-ink-2">
                                    <slot name="empty">
                                        <p>{{ activeFilters ? 'No rows match these filters.' : emptyText }}</p>
                                        <Link v-if="!activeFilters && emptyAction" :href="emptyAction.href" class="mt-3 inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">{{ emptyAction.label }}</Link>
                                    </slot>
                                    <button v-if="activeFilters" type="button" class="mt-2 text-accent-text hover:underline" @click="state.columnFilters.value = []">Clear filters</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="hasTotals && rows.length > 0" class="sticky bottom-0 bg-surface-2">
                            <tr class="h-(--row-h) font-medium">
                                <td v-if="selectable" class="border-t border-line" />
                                <td v-for="({ column, meta }, i) in columns" :key="column.id" class="border-t border-line px-3" :class="meta.total ? 'num' : ''">
                                    <template v-if="meta.total">{{ totals[column.id] }}</template>
                                    <template v-else-if="i === 0">Total</template>
                                </td>
                                <td class="border-t border-line" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </ContextMenuTrigger>
            <ContextMenuPortal>
                <ContextMenuContent class="z-50 w-56 rounded-panel border border-line bg-surface p-1 text-ui text-ink shadow-float">
                    <ContextMenuItem class="flex h-8 items-center gap-2 rounded-control px-2 outline-none data-[highlighted]:bg-surface-2" @select="open(contextRow)">Open <Kbd keys="Enter" class="ml-auto" /></ContextMenuItem>
                    <ContextMenuItem v-if="contextHref" class="flex h-8 items-center gap-2 rounded-control px-2 outline-none data-[highlighted]:bg-surface-2" @select="pinContext">Open as a pinned tab <Kbd keys="Ctrl+Click" class="ml-auto" /></ContextMenuItem>
                    <ContextMenuItem v-if="selectable" class="flex h-8 items-center gap-2 rounded-control px-2 outline-none data-[highlighted]:bg-surface-2" @select="state.toggleRow(contextRow)">Select or unselect <Kbd keys="Space" class="ml-auto" /></ContextMenuItem>
                    <ContextMenuSeparator class="my-1 h-px bg-line" />
                    <ContextMenuItem class="flex h-8 items-center gap-2 rounded-control px-2 outline-none data-[highlighted]:bg-surface-2" @select="copyContext">Copy {{ columns[0]?.meta.header.toLowerCase() }}</ContextMenuItem>
                </ContextMenuContent>
            </ContextMenuPortal>
        </ContextMenuRoot>
    </div>
</template>
