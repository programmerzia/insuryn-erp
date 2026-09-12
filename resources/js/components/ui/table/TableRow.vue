<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <tr :class="cn('border-b border-line transition-colors hover:bg-surface-raised', props.class)">
        <slot />
    </tr>
</template>
