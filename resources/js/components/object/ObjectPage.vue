<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { BookOpen } from 'lucide-vue-next';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import AccountingList from '@/components/object/AccountingList.vue';
import AuditList from '@/components/object/AuditList.vue';
import SkeletonRows from '@/components/object/SkeletonRows.vue';
import Timeline from '@/components/object/Timeline.vue';
import type { AccountingJournal, AuditRow, TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import type { Crumb } from '@/lib/drill';

/**
 * UX brief §6.2 object page: header strip (number, status, key amounts, actions), tabs Overview · Transactions · Timeline · Accounting ·
 * Documents · Audit. "View accounting" opens the journals in a side panel (brief §1.5). Accounting and audit load after the page with
 * skeleton rows in place. The open tab is kept in the URL.
 */
const props = defineProps<{
    title: string;
    subtitle?: string;
    status: string;
    facts: { label: string; value: string; num?: boolean }[];
    crumbs: Crumb[];
    currency: string;
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
    transactionsLabel?: string;
}>();

const initial = typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('tab') ?? 'overview') : 'overview';
const tab = ref(initial);
const panel = ref(false);
watch(tab, (value) => {
    const params = new URLSearchParams(window.location.search);
    if (value === 'overview') params.delete('tab');
    else params.set('tab', value);
    const query = params.toString();
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
});
const tabs = [
    { value: 'overview', label: 'Overview' },
    { value: 'transactions', label: props.transactionsLabel ?? 'Transactions' },
    { value: 'timeline', label: 'Timeline' },
    { value: 'accounting', label: 'Accounting' },
    { value: 'documents', label: 'Documents' },
    { value: 'audit', label: 'Audit' },
];
</script>

<template>
    <div class="flex min-h-full flex-col">
        <header class="border-b border-line px-6 pt-3">
            <Breadcrumb :base="crumbs" />
            <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pb-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h1 class="text-title font-semibold">{{ title }}</h1>
                        <StatusBadge :status="status" />
                    </div>
                    <p v-if="subtitle" class="truncate text-ui text-ink-2">{{ subtitle }}</p>
                </div>
                <dl class="flex flex-wrap gap-x-8 gap-y-1">
                    <div v-for="fact in facts" :key="fact.label">
                        <dt class="text-dense text-ink-2">{{ fact.label }}</dt>
                        <dd class="text-ui font-medium" :class="{ 'tabular-nums': fact.num }">{{ fact.value }}</dd>
                    </div>
                </dl>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control px-2 text-ui text-ink-2 hover:bg-surface-2 hover:text-ink" @click="panel = true">
                        <BookOpen :size="16" :stroke-width="1.5" />View accounting
                    </button>
                    <slot name="actions" />
                </div>
            </div>
        </header>
        <TabsRoot v-model="tab" class="flex flex-1 flex-col">
            <TabsList class="flex gap-5 border-b border-line px-6" aria-label="Sections">
                <template v-for="item in tabs" :key="item.value">
                    <TabsTrigger v-if="item.value !== 'transactions' || $slots.transactions" :value="item.value" class="-mb-px h-9 border-b-2 border-transparent text-ui text-ink-2 hover:text-ink data-[state=active]:border-accent data-[state=active]:text-ink">
                        {{ item.label }}
                    </TabsTrigger>
                </template>
            </TabsList>
            <div class="flex-1 px-6 py-4">
                <TabsContent value="overview" class="outline-none"><slot name="overview" /></TabsContent>
                <TabsContent v-if="$slots.transactions" value="transactions" class="outline-none"><slot name="transactions" /></TabsContent>
                <TabsContent value="timeline" class="outline-none"><Timeline :entries="timeline ?? []" /></TabsContent>
                <TabsContent value="accounting" class="max-w-[900px] outline-none">
                    <Deferred data="accounting"><template #fallback><SkeletonRows /></template><AccountingList :journals="accounting ?? []" :currency="currency" :from="title" /></Deferred>
                </TabsContent>
                <TabsContent value="documents" class="outline-none">
                    <p class="text-ui text-ink-2">Documents cannot be attached yet. Keep them in your document store and note the reference on the record.</p>
                </TabsContent>
                <TabsContent value="audit" class="outline-none">
                    <Deferred data="audit"><template #fallback><SkeletonRows /></template><AuditList :rows="audit ?? []" /></Deferred>
                </TabsContent>
            </div>
        </TabsRoot>
        <Drawer v-model:open="panel" :title="`Accounting for ${title}`" width="w-[680px]">
            <Deferred data="accounting"><template #fallback><SkeletonRows /></template><AccountingList :journals="accounting ?? []" :currency="currency" :from="title" /></Deferred>
        </Drawer>
    </div>
</template>
