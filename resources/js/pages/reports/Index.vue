<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { startTrail } from '@/lib/drill';

/**
 * Report catalogue as a plain list (brief §9: no card grids). Every figure in a report drills to the activity or record behind it. Flow fix X12: each report's
 * table downloads from here on its default filter (this month, or as of today), without opening it. UX consistency pass: every row — the reports, the
 * registers on their own screens and the regulatory exports — shows the same export links in the same order and words (CSV, XLSX, PDF).
 */
type Format = 'csv' | 'xlsx' | 'pdf';
interface Report { key: string | null; title: string; description: string; filter: string | null; href?: string; exports?: Partial<Record<Format, string>> }
defineProps<{ reports: Report[] }>();
const FORMATS: Format[] = ['csv', 'xlsx', 'pdf'];
startTrail();
</script>

<template>
    <AppLayout help="reports" title="Reports">
        <div class="max-w-[860px]">
            <PageHeader title="Reports" description="Every figure links to the account activity or record behind it." />
            <ul class="rounded-panel border border-line">
                <li v-for="report in reports" :key="report.title" class="flex items-center border-b border-line last:border-b-0">
                    <Link :href="report.href ?? `/reports/${report.key}`" class="group flex min-w-0 flex-1 items-center gap-4 px-4 py-3 hover:bg-surface-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-ui font-medium text-ink">{{ report.title }}</p>
                            <p class="text-ui text-ink-2">{{ report.description }}</p>
                        </div>
                        <ArrowRight :size="16" :stroke-width="1.5" class="text-ink-2 group-hover:text-ink" aria-hidden="true" />
                    </Link>
                    <div class="flex w-[148px] shrink-0 items-center justify-end gap-1 pr-3 text-ui max-sm:w-auto" role="group" :aria-label="`Export ${report.title}`">
                        <template v-for="format in FORMATS" :key="format">
                            <a v-if="report.exports?.[format]" :href="report.exports[format]" class="inline-flex h-8 items-center rounded-control px-2 text-accent-text hover:bg-surface-2" :aria-label="`Export ${report.title} as ${format.toUpperCase()}`" download>{{ format.toUpperCase() }}</a>
                        </template>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
