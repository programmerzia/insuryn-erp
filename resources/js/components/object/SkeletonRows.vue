<script setup lang="ts">
/** Placeholder rows while data loads in place (brief §4: never a spinner over the screen). */
withDefaults(defineProps<{ rows?: number }>(), { rows: 4 });
</script>

<template>
    <div class="grid gap-2" aria-busy="true" aria-label="Loading">
        <div v-for="n in rows" :key="n" class="flex h-(--row-h) items-center gap-4 border-b border-line">
            <span class="h-2.5 w-24 animate-pulse rounded-control bg-surface-2" />
            <span class="h-2.5 flex-1 animate-pulse rounded-control bg-surface-2" />
            <span class="h-2.5 w-20 animate-pulse rounded-control bg-surface-2" />
        </div>
    </div>
</template>
