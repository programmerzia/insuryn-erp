<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

const page = usePage<SharedProps>();
const error = computed(() => page.props.errors.form);
</script>

<template>
    <p v-if="error" class="mb-4 rounded-md border border-brick-soft/50 bg-brick/15 px-3 py-2 text-sm text-brick-soft" role="alert">{{ error }}</p>
</template>
