<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Logo from '@/components/Logo.vue';
import { Card } from '@/components/ui/card';
import type { SharedProps } from '@/types/shared';

defineProps<{ title: string; description?: string }>();

const page = usePage<SharedProps>();
const tenant = computed(() => page.props.tenant);
const status = computed(() => page.props.status);
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen items-center justify-center bg-surface px-4 py-10">
        <div class="w-full max-w-sm">
            <p class="mb-6 flex items-center justify-center gap-2 text-section font-semibold text-ink">
                <Logo :size="24" />
                Insuryn
            </p>
            <Card>
                <h1 class="text-title font-semibold">{{ title }}</h1>
                <p v-if="tenant" class="text-ui text-ink-2">{{ tenant.name }}</p>
                <p v-if="description" class="mt-2 text-ui text-ink-2">{{ description }}</p>
                <p v-if="status" class="mt-4 rounded-control border border-line bg-surface-2 px-3 py-2 text-ui text-ok">{{ status }}</p>
                <div class="mt-6"><slot /></div>
            </Card>
        </div>
    </div>
</template>
