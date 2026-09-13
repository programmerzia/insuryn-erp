import {
    type ColumnDef,
    type ColumnFiltersState,
    type ColumnOrderState,
    type ColumnSizingState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    type RowSelectionState,
    type SortingState,
    type Updater,
    useVueTable,
    type VisibilityState,
} from '@tanstack/vue-table';
import { computed, type Ref, ref, watch } from 'vue';
import type { DataColumn, ServerPage } from '@/components/table/types';
import { formatMinor, parseMoney } from '@/lib/money';
import { savePreference, type SavedView, usePreferences } from '@/lib/preferences';
import { useStatusBar } from '@/lib/statusbar';
import { decodeTableState, encodeTableState, matchesFilter } from '@/lib/table-state';

const DEFAULT_WIDTHS = { money: 136, number: 88, date: 112, status: 148, text: 200 } as const;

export interface DataTableOptions<T> {
    id: string;
    columns: () => DataColumn<T>[];
    rows: () => T[];
    rowKey: (row: T) => string;
    page: () => ServerPage | undefined;
    urlSync: boolean;
    selectable: () => boolean;
}

function resolve<V>(updater: Updater<V>, current: V): V {
    return typeof updater === 'function' ? (updater as (old: V) => V)(current) : updater;
}

function compare(type: DataColumn<unknown>['type'], a: unknown, b: unknown): number {
    if (a === null || a === undefined || a === '') return b === null || b === undefined || b === '' ? 0 : -1;
    if (b === null || b === undefined || b === '') return 1;
    if (type === 'money' || type === 'number') {
        const [x, y] = [parseMoney(String(a)) ?? 0n, parseMoney(String(b)) ?? 0n];
        return x === y ? 0 : x < y ? -1 : 1;
    }
    return String(a).localeCompare(String(b), 'en', { numeric: true, sensitivity: 'base' });
}

/** State behind DataTable: sorting, filters, layout and views persisted per user, filters in the URL, selection, keyboard focus, status bar. */
export function useDataTable<T>(options: DataTableOptions<T>) {
    const preferences = usePreferences();
    const bar = useStatusBar();
    const layout = preferences.tables[options.id] ?? {};
    const fromUrl = options.urlSync && typeof window !== 'undefined' ? decodeTableState(new URLSearchParams(window.location.search)) : { filters: {}, sort: [] };

    const sorting = ref<SortingState>(fromUrl.sort.length ? fromUrl.sort : (layout.sort ?? []));
    const columnFilters = ref<ColumnFiltersState>(Object.entries(fromUrl.filters).map(([id, value]) => ({ id, value })));
    const columnOrder = ref<ColumnOrderState>(layout.columns ?? []);
    const columnVisibility = ref<VisibilityState>(Object.fromEntries((layout.hidden ?? []).map((id) => [id, false])));
    const columnSizing = ref<ColumnSizingState>({ ...(layout.widths ?? {}) });
    const rowSelection = ref<RowSelectionState>({});
    const activeIndex = ref(-1);
    const anchorIndex = ref(-1);
    const showFilters = ref(columnFilters.value.length > 0);

    const definitions = computed<ColumnDef<T>[]>(() =>
        options.columns().map((column) => ({
            id: column.id,
            accessorFn: (row: T) => column.value(row),
            size: column.width ?? DEFAULT_WIDTHS[column.type ?? 'text'],
            minSize: 56,
            maxSize: 800,
            enableHiding: column.hideable !== false,
            sortingFn: (a, b, id) => compare(column.type as never, a.getValue(id), b.getValue(id)),
            filterFn: (row, id, value) => matchesFilter(row.getValue(id), String(value ?? ''), column.type ?? 'text'),
            meta: column,
        })),
    );

    const table = useVueTable<T>({
        get data() {
            return options.rows();
        },
        get columns() {
            return definitions.value;
        },
        getRowId: (row) => options.rowKey(row),
        state: {
            get sorting() { return sorting.value; },
            get columnFilters() { return columnFilters.value; },
            get columnOrder() { return columnOrder.value; },
            get columnVisibility() { return columnVisibility.value; },
            get columnSizing() { return columnSizing.value; },
            get rowSelection() { return rowSelection.value; },
        },
        onSortingChange: (u) => (sorting.value = resolve(u, sorting.value)),
        onColumnFiltersChange: (u) => (columnFilters.value = resolve(u, columnFilters.value)),
        onColumnOrderChange: (u) => (columnOrder.value = resolve(u, columnOrder.value)),
        onColumnVisibilityChange: (u) => (columnVisibility.value = resolve(u, columnVisibility.value)),
        onColumnSizingChange: (u) => (columnSizing.value = resolve(u, columnSizing.value)),
        onRowSelectionChange: (u) => (rowSelection.value = resolve(u, rowSelection.value)),
        enableMultiSort: true,
        isMultiSortEvent: (event) => (event as MouseEvent).shiftKey,
        enableRowSelection: () => options.selectable(),
        columnResizeMode: 'onChange',
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
    });

    const rows = computed(() => table.getRowModel().rows);
    const columns = computed(() => table.getVisibleLeafColumns().map((c) => ({ column: c, meta: c.columnDef.meta as DataColumn<T> })));
    const sumColumn = computed(() => options.columns().find((c) => c.total));
    const selectedRows = computed(() => table.getSelectedRowModel().rows.map((r) => r.original));
    const totals = computed(() => {
        const result: Record<string, string> = {};
        for (const column of options.columns().filter((c) => c.total)) {
            result[column.id] = formatMinor(rows.value.reduce((sum, row) => sum + (parseMoney(String(column.value(row.original) ?? '')) ?? 0n), 0n));
        }
        return result;
    });

    // Persist the layout per user, and keep filters and sort in the URL (replacing the entry, so Back returns to the previous page).
    watch([columnOrder, columnVisibility, columnSizing, sorting], () => {
        savePreference(`tables.${options.id}`, {
            columns: columnOrder.value,
            hidden: Object.entries(columnVisibility.value).filter(([, visible]) => !visible).map(([id]) => id),
            widths: Object.fromEntries(Object.entries(columnSizing.value).map(([id, w]) => [id, Math.round(w)])),
            sort: sorting.value,
        }, 800);
    }, { deep: true });
    watch([columnFilters, sorting], () => {
        if (!options.urlSync || typeof window === 'undefined') return;
        const filters = Object.fromEntries(columnFilters.value.map((f) => [f.id, String(f.value ?? '')]));
        const params = encodeTableState({ filters, sort: sorting.value }, new URLSearchParams(window.location.search));
        const query = params.toString();
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
        activeIndex.value = Math.min(activeIndex.value, rows.value.length - 1);
    }, { deep: true });

    // Status bar (brief §3): rows, selection count and Σ of the selected amounts, pagination.
    watch([rows, selectedRows, () => options.page()], () => {
        const page = options.page();
        bar.rows = page ? page.total : rows.value.length;
        bar.selected = selectedRows.value.length;
        const column = sumColumn.value;
        bar.selectedSum = column ? formatMinor(selectedRows.value.reduce((sum, row) => sum + (parseMoney(String(column.value(row) ?? '')) ?? 0n), 0n)) : null;
        bar.page = page ? { current: page.current, last: page.last, go: page.go } : null;
    }, { immediate: true });

    function setFilter(id: string, value: string): void {
        table.getColumn(id)?.setFilterValue(value === '' ? undefined : value);
    }

    function filterValue(id: string): string {
        return String(columnFilters.value.find((f) => f.id === id)?.value ?? '');
    }

    function clearSelection(): void {
        rowSelection.value = {};
    }

    function toggleRow(index: number): void {
        rows.value[index]?.toggleSelected();
        anchorIndex.value = index;
    }

    /** Shift+↑↓: select from the anchor to the new active row. */
    function extendTo(index: number): void {
        const anchor = anchorIndex.value === -1 ? activeIndex.value : anchorIndex.value;
        if (anchorIndex.value === -1) anchorIndex.value = anchor;
        const [from, to] = [Math.min(anchor, index), Math.max(anchor, index)];
        const next: RowSelectionState = {};
        rows.value.slice(from, to + 1).forEach((row) => (next[row.id] = true));
        rowSelection.value = next;
    }

    function moveColumn(id: string, beforeId: string): void {
        const order = table.getAllLeafColumns().map((c) => c.id);
        const current = columnOrder.value.length ? columnOrder.value.filter((c) => order.includes(c)).concat(order.filter((c) => !columnOrder.value.includes(c))) : order;
        const without = current.filter((c) => c !== id);
        without.splice(Math.max(0, without.indexOf(beforeId)), 0, id);
        columnOrder.value = without;
    }

    function resetLayout(): void {
        columnOrder.value = [];
        columnVisibility.value = {};
        columnSizing.value = {};
    }

    function currentView(name: string): SavedView {
        const filters = Object.fromEntries(columnFilters.value.map((f) => [f.id, String(f.value ?? '')]));
        return { name, query: encodeTableState({ filters, sort: sorting.value }).toString(), columns: table.getVisibleLeafColumns().map((c) => c.id) };
    }

    function applyView(view: SavedView): void {
        const state = decodeTableState(new URLSearchParams(view.query));
        columnFilters.value = Object.entries(state.filters).map(([id, value]) => ({ id, value }));
        sorting.value = state.sort;
        showFilters.value = columnFilters.value.length > 0;
        if (view.columns?.length) {
            columnVisibility.value = Object.fromEntries(table.getAllLeafColumns().map((c) => [c.id, view.columns?.includes(c.id) ?? true]));
        }
    }

    function saveView(name: string): void {
        const views = (preferences.views[options.id] ?? []).filter((v) => v.name !== name);
        savePreference(`views.${options.id}`, [...views, currentView(name)].slice(-20), 0);
    }

    function deleteView(name: string): void {
        savePreference(`views.${options.id}`, (preferences.views[options.id] ?? []).filter((v) => v.name !== name), 0);
    }

    const views = computed(() => preferences.views[options.id] ?? []);

    return {
        table, rows, columns, totals, selectedRows, sorting, columnFilters, showFilters, activeIndex, anchorIndex, views,
        setFilter, filterValue, clearSelection, toggleRow, extendTo, moveColumn, resetLayout, applyView, saveView, deleteView,
    } as const;
}

export type DataTableState<T> = ReturnType<typeof useDataTable<T>>;
export type { Ref };
