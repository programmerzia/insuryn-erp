<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ options: { value: string; label: string }[]; placeholder?: string; class?: HTMLAttributes['class'] }>();
const model = defineModel<string | null>({ default: '' });
</script>

<template>
    <select v-model="model" :class="cn('h-9 w-full rounded-md border border-line-control bg-surface px-2 text-sm text-ivory', props.class)">
        <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
</template>
