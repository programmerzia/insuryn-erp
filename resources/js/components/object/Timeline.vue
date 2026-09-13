<script setup lang="ts">
import type { TimelineEntry } from '@/components/object/types';
import { formatDate } from '@/lib/format';

/** The object's story, newest first, in plain sentences (brief §6.2). */
defineProps<{ entries: TimelineEntry[] }>();
</script>

<template>
    <ol v-if="entries.length" class="grid max-w-[760px]" aria-label="Timeline">
        <li v-for="(entry, index) in entries" :key="index" class="grid grid-cols-[96px_12px_1fr] gap-3">
            <time class="pt-2 text-right text-dense text-ink-2 tabular-nums" :datetime="entry.date">{{ formatDate(entry.date) }}</time>
            <span class="relative flex justify-center" aria-hidden="true">
                <span class="absolute inset-y-0 w-px bg-line" :class="{ 'top-3': index === 0, 'bottom-auto h-3': index === entries.length - 1 }" />
                <span class="relative mt-2.5 size-2 rounded-full border-2 border-surface bg-ink-2" :class="{ 'bg-accent': index === 0 }" />
            </span>
            <p class="py-1.5 text-ui">{{ entry.sentence }}</p>
        </li>
    </ol>
    <p v-else class="text-ui text-ink-2">Nothing has happened to this record yet.</p>
</template>
