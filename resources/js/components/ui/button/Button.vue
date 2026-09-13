<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/** Brief §2: 32px controls on the 4px grid, 4px radius, one accent. `primary` is the one action a view leads with; `danger` only where money or data is destroyed. */
const props = withDefaults(
    defineProps<{ variant?: 'primary' | 'default' | 'secondary' | 'ghost' | 'danger'; size?: 'md' | 'sm' | 'icon'; type?: 'button' | 'submit'; disabled?: boolean; class?: HTMLAttributes['class'] }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false, class: undefined },
);

const variants = {
    primary: 'bg-accent text-accent-ink hover:bg-accent-hover',
    default: 'bg-accent text-accent-ink hover:bg-accent-hover',
    secondary: 'border border-line-control bg-surface text-ink hover:bg-surface-2',
    ghost: 'text-ink-2 hover:bg-surface-2 hover:text-ink',
    danger: 'border border-danger bg-surface text-danger hover:bg-surface-2',
};
const sizes = { md: 'h-8 px-3', sm: 'h-7 px-2', icon: 'size-8 px-0' };
const classes = computed(() =>
    cn('inline-flex shrink-0 items-center justify-center gap-1.5 rounded-control text-ui font-medium whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-50', variants[props.variant], sizes[props.size], props.class),
);
</script>

<template>
    <button :type="type" :disabled="disabled" :class="classes"><slot /></button>
</template>
