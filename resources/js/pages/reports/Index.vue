<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ reports: { key: string | null; title: string; description: string; filter: string | null; href?: string }[] }>();
</script>

<template>
    <AppLayout title="Reports">
        <PageHeader eyebrow="Accounting" title="Reports" description="Every figure links to the account activity or journal behind it." />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link v-for="report in reports" :key="report.title" :href="report.href ?? `/reports/${report.key}`">
                <Card class="h-full hover:border-line-control">
                    <h2 class="font-semibold text-ivory">{{ report.title }}</h2>
                    <p class="mt-1 text-sm text-ivory-dim">{{ report.description }}</p>
                </Card>
            </Link>
        </div>
    </AppLayout>
</template>
