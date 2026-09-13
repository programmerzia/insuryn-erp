<script setup lang="ts">
import { computed } from 'vue';
import { formatKeys } from '@/lib/shortcuts';

const props = defineProps<{ keys: string }>();
const parts = computed(() => formatKeys(props.keys));
</script>

<template>
    <span class="inline-flex items-center gap-0.5 text-dense text-ink-2" :aria-label="keys">
        <kbd v-for="part in parts" :key="part" class="rounded-control border border-line bg-surface-2 px-1 font-sans leading-4">{{ part }}</kbd>
    </span>
</template>
