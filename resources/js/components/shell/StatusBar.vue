<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { usePreferences } from '@/lib/preferences';
import { useStatusBar } from '@/lib/statusbar';
import type { SharedProps } from '@/types/shared';

const bar = useStatusBar();
const page = usePage<SharedProps>();
const preferences = usePreferences();
const shell = computed(() => page.props.shell);
const branch = computed(() => shell.value?.branches.find((b) => b.id === preferences.branch_id)?.name ?? 'All branches');
const facts = computed(() => {
    const parts: string[] = [];
    if (bar.rows !== null) parts.push(`${bar.rows.toLocaleString('en-US')} ${bar.rows === 1 ? 'row' : 'rows'}`);
    if (bar.selected > 0) parts.push(`${bar.selected} selected`);
    if (bar.selectedSum !== null && bar.selected > 0) parts.push(`Σ ${bar.selectedSum}`);
    return parts;
});
</script>

<template>
    <footer class="flex h-(--statusbar-h) items-center gap-4 border-t border-line bg-surface-2 px-3 text-dense text-ink-2" aria-label="Status">
        <p class="num flex min-w-0 gap-2 text-left" role="status" aria-live="polite">
            <template v-for="(fact, index) in facts" :key="fact"><span v-if="index > 0" aria-hidden="true">·</span><span :class="{ 'text-ink': fact.startsWith('Σ') }">{{ fact }}</span></template>
            <span v-if="bar.message" class="text-ink">{{ bar.message }}</span>
        </p>
        <div class="ml-auto flex items-center gap-4">
            <div v-if="bar.page && bar.page.last > 1" class="num flex items-center gap-1">
                <button type="button" class="inline-flex size-5 items-center justify-center rounded-control hover:bg-surface disabled:opacity-40" :disabled="bar.page.current <= 1" aria-label="Previous page" @click="bar.page.go(bar.page.current - 1)">
                    <ChevronLeft :size="14" :stroke-width="1.5" />
                </button>
                <span>{{ bar.page.current }} / {{ bar.page.last }}</span>
                <button type="button" class="inline-flex size-5 items-center justify-center rounded-control hover:bg-surface disabled:opacity-40" :disabled="bar.page.current >= bar.page.last" aria-label="Next page" @click="bar.page.go(bar.page.current + 1)">
                    <ChevronRight :size="14" :stroke-width="1.5" />
                </button>
            </div>
            <span v-if="shell?.entity">{{ shell.entity.name }} · {{ branch }} · {{ shell.entity.currency }}</span>
        </div>
    </footer>
</template>
