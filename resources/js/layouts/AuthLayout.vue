<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Card } from '@/components/ui/card';
import type { SharedProps } from '@/types/shared';

defineProps<{ title: string; description?: string }>();

const page = usePage<SharedProps>();
const tenant = computed(() => page.props.tenant);
const status = computed(() => page.props.status);
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen items-center justify-center bg-bg px-4 py-10">
        <div class="w-full max-w-sm">
            <p class="mb-6 flex items-center justify-center gap-2 font-display text-xl font-bold tracking-tight text-ivory">
                <span class="inline-block size-2.5 bg-brick" aria-hidden="true" />
                Insuryn
            </p>
            <Card>
                <h1 class="text-xl font-bold">{{ title }}</h1>
                <p v-if="tenant" class="mt-1 text-xs font-semibold uppercase tracking-wider text-blueprint">{{ tenant.name }}</p>
                <p v-if="description" class="mt-2 text-sm text-ivory-dim">{{ description }}</p>
                <p v-if="status" class="mt-4 rounded-md border border-line bg-surface-raised px-3 py-2 text-sm text-green">{{ status }}</p>
                <div class="mt-6"><slot /></div>
            </Card>
        </div>
    </div>
</template>
