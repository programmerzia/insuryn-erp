<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <caption :class="cn('mt-3 text-ui text-ink-2', props.class)">
        <slot />
    </caption>
</template>
