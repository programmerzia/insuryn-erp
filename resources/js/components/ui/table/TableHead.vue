<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <th :class="cn('sticky top-0 h-(--row-h) border-b border-line bg-surface-2 px-3 text-left align-middle text-dense font-medium whitespace-nowrap text-ink-2', props.class)">
        <slot />
    </th>
</template>
