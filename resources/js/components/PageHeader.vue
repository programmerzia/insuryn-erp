<script setup lang="ts">
defineProps<{ title: string; eyebrow?: string; description?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p v-if="eyebrow" class="text-xs font-semibold tracking-wider text-blueprint uppercase">{{ eyebrow }}</p>
            <h1 class="mt-1 text-3xl font-bold">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-sm text-ivory-dim">{{ description }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
