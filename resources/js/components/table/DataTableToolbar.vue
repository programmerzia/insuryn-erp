<script setup lang="ts">
import { Bookmark, Columns3, Download, Filter, Rows3, Trash2, X } from 'lucide-vue-next';
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, DropdownMenuCheckboxItem, DropdownMenuItemIndicator } from 'reka-ui';
import { ref } from 'vue';
import { Menu, MenuContent, MenuItem, MenuLabel, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import Kbd from '@/components/ui/Kbd.vue';
import { Check } from 'lucide-vue-next';
import { savePreference, usePreferences } from '@/lib/preferences';
import { shortcutKeys } from '@/lib/shortcuts';
import type { SavedView } from '@/lib/preferences';

/** Queue toolbar (brief §6.1): view selector, filter, density, columns, export. Replaced by the bulk-action bar while rows are selected. */
defineProps<{
    views: SavedView[];
    columns: { id: string; header: string; visible: boolean; hideable: boolean }[];
    filtersShown: boolean;
    activeFilters: number;
    selected: number;
    selectedSum: string | null;
    compact?: boolean;
}>();
const emit = defineEmits<{
    toggleFilters: [];
    toggleColumn: [id: string, visible: boolean];
    resetColumns: [];
    applyView: [view: SavedView];
    saveView: [name: string];
    deleteView: [name: string];
    exportCsv: [];
    clearSelection: [];
}>();

const preferences = usePreferences();
const naming = ref(false);
const viewName = ref('');
const button = 'inline-flex h-8 items-center gap-1.5 rounded-control px-2 text-ui text-ink-2 hover:bg-surface-2 hover:text-ink';

function save(): void {
    if (viewName.value.trim() === '') return;
    emit('saveView', viewName.value.trim());
    naming.value = false;
    viewName.value = '';
}
</script>

<template>
    <div v-if="selected > 0" class="flex h-11 items-center gap-2 border-b border-line bg-accent-soft px-3" role="toolbar" aria-label="Selected rows">
        <span class="num text-left text-ui font-medium">{{ selected }} selected<template v-if="selectedSum"> · Σ {{ selectedSum }}</template></span>
        <div class="ml-2 flex items-center gap-2"><slot name="bulk" /></div>
        <button type="button" :class="[button, 'ml-auto']" @click="emit('clearSelection')"><X :size="16" :stroke-width="1.5" />Clear selection</button>
    </div>
    <div v-else class="flex h-11 items-center gap-1 border-b border-line px-3 whitespace-nowrap" role="toolbar" aria-label="Table">
        <slot name="start" />
        <div class="ml-auto flex items-center gap-1">
            <Menu>
                <MenuTrigger :class="button" title="Saved views"><Bookmark :size="16" :stroke-width="1.5" /><span :class="{ 'sr-only': compact }">Views</span></MenuTrigger>
                <MenuContent width="w-64">
                    <MenuLabel>Saved views</MenuLabel>
                    <p v-if="views.length === 0" class="px-2 pb-2 text-dense text-ink-2">No saved views yet. Filter and sort the table, then save it as a view.</p>
                    <MenuItem v-for="view in views" :key="view.name" @select="emit('applyView', view)">
                        <span class="truncate">{{ view.name }}</span>
                        <button type="button" class="ml-auto inline-flex size-6 items-center justify-center rounded-control text-ink-2 hover:text-danger" :aria-label="`Delete view ${view.name}`" @click.stop="emit('deleteView', view.name)">
                            <Trash2 :size="14" :stroke-width="1.5" />
                        </button>
                    </MenuItem>
                    <MenuSeparator class="my-1 h-px bg-line" />
                    <MenuItem @select="naming = true">Save the current view…</MenuItem>
                </MenuContent>
            </Menu>
            <button type="button" :class="[button, filtersShown && 'bg-surface-2 text-ink']" :aria-pressed="filtersShown" :title="`Filter the table (${shortcutKeys('table.filter')})`" @click="emit('toggleFilters')">
                <Filter :size="16" :stroke-width="1.5" /><span :class="{ 'sr-only': compact }">Filter</span><span v-if="activeFilters" class="num">({{ activeFilters }})</span>
            </button>
            <button type="button" :class="button" :title="`Row density (${shortcutKeys('app.density')})`" @click="savePreference('density', preferences.density === 'compact' ? 'comfortable' : 'compact', 0)">
                <Rows3 :size="16" :stroke-width="1.5" /><span :class="{ 'sr-only': compact }">{{ preferences.density === 'compact' ? 'Compact' : 'Comfortable' }}</span>
            </button>
            <Menu>
                <MenuTrigger :class="button" title="Show or hide columns"><Columns3 :size="16" :stroke-width="1.5" /><span :class="{ 'sr-only': compact }">Columns</span></MenuTrigger>
                <MenuContent width="w-56">
                    <MenuLabel>Show columns</MenuLabel>
                    <DropdownMenuCheckboxItem
                        v-for="column in columns"
                        :key="column.id"
                        :model-value="column.visible"
                        :disabled="!column.hideable"
                        class="relative flex h-8 cursor-default items-center rounded-control pr-2 pl-7 text-ui outline-none select-none data-[disabled]:opacity-50 data-[highlighted]:bg-surface-2"
                        @update:model-value="(value: boolean | 'indeterminate') => emit('toggleColumn', column.id, value === true)"
                        @select.prevent
                    >
                        <DropdownMenuItemIndicator class="absolute left-2 inline-flex"><Check :size="14" :stroke-width="1.5" /></DropdownMenuItemIndicator>
                        {{ column.header }}
                    </DropdownMenuCheckboxItem>
                    <MenuSeparator class="my-1 h-px bg-line" />
                    <MenuItem @select="emit('resetColumns')">Reset columns</MenuItem>
                </MenuContent>
            </Menu>
            <button type="button" :class="button" title="Export the rows as CSV" @click="emit('exportCsv')"><Download :size="16" :stroke-width="1.5" /><span :class="{ 'sr-only': compact }">Export</span></button>
        </div>
    </div>

    <DialogRoot v-model:open="naming">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <DialogContent class="fixed top-[20vh] left-1/2 z-50 w-96 max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-panel border border-line bg-surface p-4 text-ink shadow-float outline-none" :aria-describedby="undefined">
                <DialogTitle class="text-section font-semibold">Save view</DialogTitle>
                <form class="mt-3 grid gap-3" @submit.prevent="save">
                    <label class="grid gap-1 text-ui font-medium" for="view-name">
                        Name
                        <input id="view-name" v-model="viewName" maxlength="60" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body font-normal" placeholder="My overdue > 30d" />
                    </label>
                    <p class="text-dense text-ink-2">Saves the filters, sort and visible columns for you.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="naming = false">Cancel <Kbd keys="Esc" /></button>
                        <button type="submit" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Save view</button>
                    </div>
                </form>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
