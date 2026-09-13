<script setup lang="ts">
import FormError from '@/components/FormError.vue';
import { Label } from '@/components/ui/label';

defineProps<{ id: string; label: string; error?: string; hint?: string }>();
</script>

<template>
    <div class="grid gap-1.5">
        <Label :for="id">{{ label }}</Label>
        <slot />
        <p v-if="hint" class="text-dense text-ink-2">{{ hint }}</p>
        <FormError :message="error" />
    </div>
</template>
