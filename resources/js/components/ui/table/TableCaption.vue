<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <caption :class="cn('mt-3 text-sm text-ivory-dim', props.class)">
        <slot />
    </caption>
</template>
