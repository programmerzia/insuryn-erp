<script setup lang="ts">
/** Page title row. Brief §2: 20px title, sentence case, no eyebrow labels (the `eyebrow` prop is accepted and not shown). */
defineProps<{ title: string; description?: string; eyebrow?: string }>();
</script>

<template>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div class="min-w-0">
            <h1 class="text-title font-semibold">{{ title }}</h1>
            <p v-if="description" class="text-ui text-ink-2">{{ description }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
