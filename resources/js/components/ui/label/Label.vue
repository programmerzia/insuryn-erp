<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ for?: string; class?: HTMLAttributes['class'] }>();
</script>

<template>
    <label :for="props.for" :class="cn('text-ui font-medium text-ink', props.class)"><slot /></label>
</template>
