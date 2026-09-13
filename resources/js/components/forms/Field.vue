<script setup lang="ts">
import { provide, toRef } from 'vue';
import type { FieldContext } from '@/lib/field';

/**
 * A form field (brief §4): label above, the control, helper text below, the error inline and specific. The control receives its id,
 * aria-describedby and aria-invalid through `fieldContext` (inputs in components/forms use it; plain controls take `id` from the slot).
 */
const props = defineProps<{ id: string; label: string; error?: string; hint?: string; optional?: boolean }>();

provide('field', toRef(() => ({
    id: props.id,
    describedBy: [props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : ''].filter(Boolean).join(' ') || undefined,
    invalid: !!props.error,
}) satisfies FieldContext));
</script>

<template>
    <div class="grid gap-1">
        <label :for="id" class="text-ui font-medium text-ink">{{ label }}<span v-if="optional" class="font-normal text-ink-2"> (optional)</span></label>
        <slot :id="id" :described-by="[hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined" :invalid="!!error" />
        <p v-if="hint && !error" :id="`${id}-hint`" class="text-dense text-ink-2">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="text-dense text-danger" role="alert">{{ error }}</p>
    </div>
</template>
