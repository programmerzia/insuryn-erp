<script setup lang="ts">
/** Label / value pairs in an inspector or object page: one grid, same edges for every pair. */
defineProps<{ items: { label: string; value?: string | number | null; num?: boolean }[] }>();
</script>

<template>
    <dl class="grid grid-cols-[minmax(7rem,auto)_1fr] gap-x-4 gap-y-2 text-ui">
        <template v-for="item in items" :key="item.label">
            <dt class="text-ink-2">{{ item.label }}</dt>
            <dd class="min-w-0 break-words" :class="item.num ? 'tabular-nums' : ''">
                <slot :name="item.label">{{ item.value === null || item.value === undefined || item.value === '' ? '—' : item.value }}</slot>
            </dd>
        </template>
    </dl>
</template>
