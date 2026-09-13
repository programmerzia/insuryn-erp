<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/** A panel: border conveys structure (brief §2), 6px radius, no shadow. */
const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <div :class="cn('rounded-panel border border-line bg-surface p-4', props.class)"><slot /></div>
</template>
