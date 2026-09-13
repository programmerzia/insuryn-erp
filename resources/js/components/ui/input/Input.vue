<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
const model = defineModel<string | number>({ default: '' });
</script>

<template>
    <input
        v-model="model"
        :class="cn('flex h-9 w-full rounded-md border border-line-control bg-surface px-3 py-1 text-sm text-ivory placeholder:text-ivory-dim/60 disabled:opacity-50', props.class)"
    />
</template>
