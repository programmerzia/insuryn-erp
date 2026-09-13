<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ options: { value: string; label: string }[]; placeholder?: string; class?: HTMLAttributes['class'] }>();
const model = defineModel<string>();
</script>

<template>
    <select v-model="model" :class="cn('h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body text-ink', props.class)">
        <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
</template>
