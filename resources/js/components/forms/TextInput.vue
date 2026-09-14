<script setup lang="ts">
import { useField } from '@/lib/field';

/** Plain text input wired to its Field (id, description, invalid state). */
const props = defineProps<{ id?: string; placeholder?: string; maxlength?: number; inputmode?: 'text' | 'decimal' | 'numeric' | 'tel' | 'email' }>();
const model = defineModel<string | number>({ default: '' });
const field = useField(props.id);
</script>

<template>
    <input v-bind="field" v-model="model" :placeholder="placeholder" :maxlength="maxlength" :inputmode="inputmode" class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body text-ink placeholder:text-ink-2 aria-[invalid=true]:border-danger" />
</template>
