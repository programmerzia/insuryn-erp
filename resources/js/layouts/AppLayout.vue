<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import TabStrip from '@/components/shell/TabStrip.vue';
import Toaster from '@/components/shell/Toaster.vue';
import TopBar from '@/components/shell/TopBar.vue';
import { openPalette } from '@/lib/palette';
import { savePreference, usePreferences } from '@/lib/preferences';
import { useShortcut } from '@/lib/shortcuts';
import { toast } from '@/lib/toasts';
import type { SharedProps } from '@/types/shared';

/**
 * Application shell (UX brief §3): top bar, pinned tabs, sidebar, main area, status bar — always the full window, like a desktop app.
 * `fill` pages (queues with an inspector) manage their own scrolling; other pages scroll inside the main area.
 */
defineProps<{ title: string; fill?: boolean }>();

const page = usePage<SharedProps>();
const preferences = usePreferences();
useShortcut('app.sidebar', () => savePreference('sidebar_collapsed', !preferences.sidebar_collapsed));
useShortcut('app.palette', openPalette, { allowInInputs: true });
useShortcut('app.theme', () => savePreference('theme', preferences.theme === 'dark' ? 'light' : 'dark', 0), { allowInInputs: true });
useShortcut('app.density', () => savePreference('density', preferences.density === 'compact' ? 'comfortable' : 'compact', 0), { allowInInputs: true });

const status = computed(() => page.props.status);
watch(status, (message) => message && toast(message, { tone: 'ok' }), { immediate: true });
</script>

<template>
    <Head :title="title" />
    <div class="grid h-screen grid-cols-[auto_minmax(0,1fr)] grid-rows-[auto_auto_minmax(0,1fr)_auto] bg-surface text-ink">
        <TopBar class="col-span-2 col-start-1 row-start-1" />
        <TabStrip class="col-span-2 col-start-1 row-start-2" />
        <Sidebar class="col-start-1 row-start-3" />
        <main id="main" class="col-start-2 row-start-3 min-h-0" :class="fill ? 'flex flex-col' : 'overflow-y-auto px-6 py-4'">
            <slot />
        </main>
        <StatusBar class="col-span-2 col-start-1 row-start-4" />
        <Toaster />
        <CommandPalette />
    </div>
</template>
