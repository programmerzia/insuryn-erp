<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, watch } from 'vue';
import ConfirmHost from '@/components/shell/ConfirmHost.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import TabStrip from '@/components/shell/TabStrip.vue';
import Toaster from '@/components/shell/Toaster.vue';
import TopBar from '@/components/shell/TopBar.vue';
import { openPalette, paletteOpen } from '@/lib/palette';
import { savePreference, usePreferences } from '@/lib/preferences';
import { useShortcut } from '@/lib/shortcuts';
import { toast } from '@/lib/toasts';
import type { SharedProps } from '@/types/shared';

/**
 * Application shell (UX brief §3): top bar, pinned tabs, sidebar, main area, status bar — always the full window, like a desktop app.
 * `fill` pages (queues with an inspector) manage their own scrolling; other pages scroll inside the main area.
 */
// The palette is loaded the first time it opens (UX brief §7: keep first-load JavaScript small).
const CommandPalette = defineAsyncComponent(() => import('@/components/shell/CommandPalette.vue'));

defineProps<{ title: string; fill?: boolean }>();

const page = usePage<SharedProps>();
const preferences = usePreferences();
useShortcut('app.sidebar', () => savePreference('sidebar_collapsed', !preferences.sidebar_collapsed));
useShortcut('app.palette', openPalette, { allowInInputs: true });
useShortcut('app.home', () => router.visit('/home'), { allowInInputs: true });
useShortcut('app.theme', () => savePreference('theme', preferences.theme === 'dark' ? 'light' : 'dark', 0), { allowInInputs: true });
useShortcut('app.density', () => savePreference('density', preferences.density === 'compact' ? 'comfortable' : 'compact', 0), { allowInInputs: true });

const status = computed(() => page.props.status);
watch(status, (message) => {
    if (!message) return;
    const undo = page.props.undo as { label: string; url: string } | null;
    toast(message, { tone: 'ok', undo: undo ? () => router.post(undo.url, {}, { preserveScroll: true }) : undefined, duration: undo ? 6000 : 4000 });
}, { immediate: true });
// Business-rule refusals from list and inspector actions (forms show theirs above the fields as well).
watch(() => page.props.errors?.form, (message) => message && toast(message, { tone: 'danger', duration: 6000 }));
</script>

<template>
    <Head :title="title" />
    <a href="#main" class="sr-only z-50 rounded-control bg-surface px-3 py-2 text-ui text-ink focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:shadow-float">Skip to the main content</a>
    <div class="grid h-screen grid-cols-[auto_minmax(0,1fr)] grid-rows-[auto_auto_minmax(0,1fr)_auto] bg-surface text-ink">
        <TopBar class="col-span-2 col-start-1 row-start-1" />
        <TabStrip class="col-span-2 col-start-1 row-start-2" />
        <Sidebar class="col-start-1 row-start-3" />
        <main id="main" tabindex="-1" class="col-start-2 row-start-3 min-h-0 outline-none" :class="fill ? 'flex flex-col' : 'overflow-y-auto px-6 py-4'">
            <slot />
        </main>
        <StatusBar class="col-span-2 col-start-1 row-start-4" />
        <Toaster />
        <CommandPalette v-if="paletteOpen" />
        <ConfirmHost />
    </div>
</template>
