<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { startTrail } from '@/lib/drill';

/**
 * Report catalogue as a plain list (brief §9: no card grids). Every figure in a report drills to the activity or record behind it. Flow fix X12: each report's
 * table downloads from here as CSV or XLSX on its default filter (this month, or as of today), without opening it.
 */
defineProps<{ reports: { key: string | null; title: string; description: string; filter: string | null; href?: string; exports?: { csv: string; xlsx: string } }[] }>();
startTrail();
</script>

<template>
    <AppLayout help="reports" title="Reports">
        <div class="max-w-[760px]">
            <h1 class="text-title font-semibold">Reports</h1>
            <p class="mb-4 text-ui text-ink-2">Every figure links to the account activity or record behind it.</p>
            <ul class="rounded-panel border border-line">
                <li v-for="report in reports" :key="report.title" class="flex items-center border-b border-line last:border-b-0">
                    <Link :href="report.href ?? `/reports/${report.key}`" class="group flex min-w-0 flex-1 items-center gap-4 px-4 py-3 hover:bg-surface-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-ui font-medium text-ink">{{ report.title }}</p>
                            <p class="text-ui text-ink-2">{{ report.description }}</p>
                        </div>
                        <ArrowRight :size="16" :stroke-width="1.5" class="text-ink-2 group-hover:text-ink" aria-hidden="true" />
                    </Link>
                    <div v-if="report.exports" class="flex shrink-0 items-center gap-1 pr-3 text-ui">
                        <a :href="report.exports.csv" class="inline-flex h-8 items-center rounded-control px-2 text-accent-text hover:bg-surface-2" :aria-label="`Export ${report.title} as CSV`" download>Export CSV</a>
                        <a :href="report.exports.xlsx" class="inline-flex h-8 items-center rounded-control px-2 text-accent-text hover:bg-surface-2" :aria-label="`Export ${report.title} as XLSX`" download>XLSX</a>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
