<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { savePreference, usePreferences } from '@/lib/preferences';
import { unpinTab } from '@/lib/tabs';

/** Brief §3: objects pinned with Ctrl+click, like a desktop app; max 8, persisted per user. */
const page = usePage();
const preferences = usePreferences();
const close = (href: string) => savePreference('tabs', unpinTab(preferences.tabs, href), 0);
const isCurrent = (href: string) => (page.url.split('?')[0] ?? '') === href;
</script>

<template>
    <div v-if="preferences.tabs.length" class="flex h-8 items-end gap-px overflow-x-auto border-b border-line bg-surface-2 px-2" role="tablist" aria-label="Pinned tabs">
        <div
            v-for="tab in preferences.tabs"
            :key="tab.href"
            class="group flex h-7 max-w-48 items-center gap-1 rounded-t-control border border-b-0 pr-1 pl-3 text-ui"
            :class="isCurrent(tab.href) ? 'border-line bg-surface text-ink' : 'border-transparent text-ink-2 hover:text-ink'"
            role="tab"
            :aria-selected="isCurrent(tab.href)"
        >
            <Link :href="tab.href" class="truncate" :title="tab.title">{{ tab.title }}</Link>
            <button type="button" class="inline-flex size-5 shrink-0 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :aria-label="`Close ${tab.title}`" @click="close(tab.href)">
                <X :size="12" :stroke-width="1.5" />
            </button>
        </div>
    </div>
</template>
