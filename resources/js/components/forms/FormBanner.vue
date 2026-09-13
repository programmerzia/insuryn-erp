<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

const page = usePage<SharedProps>();
const error = computed(() => page.props.errors.form);
</script>

<template>
    <p v-if="error" class="mb-4 rounded-control border border-danger/50 bg-accent/15 px-3 py-2 text-ui text-danger" role="alert">{{ error }}</p>
</template>
