<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: true });
const props = defineProps<{ class?: HTMLAttributes['class'] }>();
const model = defineModel<string | number>();
</script>

<template>
    <input
        v-model="model"
        :class="cn('flex h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body text-ink placeholder:text-ink-2 disabled:opacity-50 aria-[invalid=true]:border-danger', props.class)"
    />
</template>
