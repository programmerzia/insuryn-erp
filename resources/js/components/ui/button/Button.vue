<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{ variant?: 'default' | 'ghost'; type?: 'button' | 'submit'; disabled?: boolean; class?: HTMLAttributes['class'] }>(), {
    variant: 'default',
    type: 'button',
    disabled: false,
    class: undefined,
});

const variants = {
    default: 'bg-brick text-ivory hover:bg-brick-hover',
    ghost: 'text-ivory-dim hover:bg-surface-raised hover:text-ivory',
};
const classes = computed(() =>
    cn('inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50', variants[props.variant], props.class),
);
</script>

<template>
    <button :type="type" :disabled="disabled" :class="classes"><slot /></button>
</template>
