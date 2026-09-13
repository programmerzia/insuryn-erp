<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { useField } from '@/lib/field';
import { cn } from '@/lib/utils';

const props = defineProps<{ options: { value: string; label: string }[]; placeholder?: string; class?: HTMLAttributes['class']; id?: string }>();
const field = useField(props.id);
const model = defineModel<string>();
</script>

<template>
    <select v-bind="field" v-model="model" :class="cn('h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body text-ink', props.class)">
        <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
</template>
