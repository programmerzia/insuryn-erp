<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <tr :class="cn('h-(--row-h) border-b border-line hover:bg-surface-2', props.class)">
        <slot />
    </tr>
</template>
