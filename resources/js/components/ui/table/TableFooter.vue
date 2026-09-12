<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <tfoot :class="cn('border-t border-line-control bg-surface-raised font-medium', props.class)">
        <slot />
    </tfoot>
</template>
