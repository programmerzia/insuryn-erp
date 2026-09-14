<script setup lang="ts" generic="T">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Inspector from '@/components/shell/Inspector.vue';
import SplitPane from '@/components/shell/SplitPane.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn, ServerPage } from '@/components/table/types';

/**
 * Brief §6.1 queue list shared by every list: toolbar (title, primary action, views, filter, density, columns, export) → table → status bar,
 * inspector on the right while a row is open. The inspector's tabs, actions and primary action come from slots and props. An empty queue shows one
 * sentence and one action (brief §4): `emptyAction`, or else the queue's own primary action.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        title: string;
        columns: DataColumn<T>[];
        rows: T[];
        rowKey: (row: T) => string;
        currency?: string;
        page?: ServerPage;
        selectable?: boolean;
        emptyText: string;
        action?: { label: string; href?: string } | null;
        inspectorTitle?: (row: T) => string;
        inspectorSubtitle?: (row: T) => string | undefined;
        primaryLabel?: (row: T) => string | undefined;
        urlSync?: boolean;
        emptyAction?: { label: string; href?: string } | null;
        /** GA-40: the server's row count when `rows` are only the first of them (a capped list without pages). */
        total?: number | null;
        /** One line next to the title (and under the empty state) when the reader lacks the queue's primary action: who can take it. */
        hint?: string | null;
    }>(),
    { currency: undefined, page: undefined, selectable: false, action: null, inspectorTitle: undefined, inspectorSubtitle: undefined, primaryLabel: undefined, urlSync: true, emptyAction: null, total: null, hint: null },
);
const emit = defineEmits<{ action: []; primary: [row: T] }>();
const active = defineModel<string | null>('active', { default: null });
const emptyState = computed(() => props.emptyAction ?? props.action ?? null);
const selected = computed(() => (active.value === null ? null : (props.rows.find((row) => props.rowKey(row) === active.value) ?? null)));
</script>

<template>
    <SplitPane :id="id" :open="selected !== null && !!$slots.details">
        <DataTable
            :id="id"
            v-model:active="active"
            :label="title"
            :columns="columns"
            :rows="rows"
            :row-key="rowKey"
            :currency="currency"
            :page="page"
            :total="total"
            :selectable="selectable"
            :empty-text="emptyText"
            :empty-action="emptyState"
            :empty-hint="hint"
            :url-sync="urlSync"
            :export-name="id"
            @close="active = null"
            @empty-action="emit('action')"
        >
            <template #toolbar>
                <h1 class="mr-3 shrink-0 text-section font-semibold">{{ title }}</h1>
                <template v-if="action">
                    <Link v-if="action.href" :href="action.href" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">{{ action.label }}</Link>
                    <button v-else type="button" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="emit('action')">{{ action.label }}</button>
                </template>
                <span v-else-if="hint" class="text-ui text-ink-2" data-testid="permission-hint">{{ hint }}</span>
                <slot name="toolbar" />
            </template>
            <template #bulk="scope"><slot name="bulk" v-bind="scope" /></template>
            <template v-for="name in Object.keys($slots).filter((slot) => slot.startsWith('cell-'))" :key="name" #[name]="scope">
                <slot :name="name" v-bind="scope" />
            </template>
        </DataTable>
        <template #inspector>
            <Inspector
                v-if="selected"
                :key="active ?? ''"
                :title="inspectorTitle ? inspectorTitle(selected) : ''"
                :subtitle="inspectorSubtitle?.(selected)"
                :primary-label="primaryLabel?.(selected)"
                @close="active = null"
                @primary="emit('primary', selected)"
            >
                <template #details><slot name="details" :row="selected" /></template>
                <template v-if="$slots.accounting" #accounting><slot name="accounting" :row="selected" /></template>
                <template v-if="$slots.history" #history><slot name="history" :row="selected" /></template>
                <template v-if="$slots.actions" #actions><slot name="actions" :row="selected" /></template>
            </Inspector>
        </template>
    </SplitPane>
</template>
