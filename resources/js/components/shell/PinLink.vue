<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { savePreference, usePreferences } from '@/lib/preferences';
import { MAX_TABS, pinTab } from '@/lib/tabs';
import { toast } from '@/lib/toasts';

/** A link to an object that pins it as a tab on Ctrl+click (⌘+click on a Mac) and opens it (brief §3). */
const props = defineProps<{ href: string; title: string }>();
const preferences = usePreferences();

function click(event: MouseEvent): void {
    if (!(event.ctrlKey || event.metaKey)) {
        return;
    }
    event.preventDefault();
    const result = pinTab(preferences.tabs, { href: props.href, title: props.title });
    if (!result.ok) {
        toast(`${MAX_TABS} tabs are pinned. Close one to pin ${props.title}.`);
        return;
    }
    savePreference('tabs', result.tabs, 0);
    router.visit(props.href);
}
</script>

<template>
    <Link :href="href" prefetch="hover" @click="click"><slot /></Link>
</template>
