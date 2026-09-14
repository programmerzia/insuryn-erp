<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

/** Design addendum v2 §B.7 monthly depreciation batch: choose a month, preview each asset's charge, post (journal preview first). Posting a month again posts nothing. */
interface PreviewRow { asset_id: string; number: string; description: string; class: string; branch: string; amount: string; accumulated: string; nbv: string }
interface RunRow { period: string; period_id: string; assets: number; total: string; posted_at: string; posted_by: string }
const props = defineProps<{
    periods: { id: string; label: string; status: string }[];
    period: { id: string; label: string; status: string; ends: string } | null;
    preview: PreviewRow[];
    total: string;
    runs: RunRow[];
    can: { post: boolean };
}>();
const confirm = useJournalConfirm();
const posted = computed(() => props.runs.filter((r) => r.period_id === props.period?.id));
const canPost = computed(() => props.can.post && props.preview.length > 0 && props.period?.status === 'open');
const monthOptions = computed(() => props.periods.map((p) => ({ value: p.id, label: `${p.label}${p.status === 'open' ? '' : ` (${p.status.replace('_', ' ')})`}` })));

function choose(id: string | undefined): void {
    if (id && id !== props.period?.id) router.get('/fixed-assets/depreciation', { period: id }, { preserveState: false });
}
function post(): void {
    if (!props.period) return;
    void confirm.request(`/fixed-assets/depreciation/${props.period.id}`, {}, `Post depreciation for ${props.period.label}?`, `Post ${formatMoney(props.total)} BDT`);
}
const previewColumns: DataColumn<PreviewRow>[] = [
    { id: 'number', header: 'Asset', value: (r) => r.number, href: (r) => `/fixed-assets/${r.asset_id}`, width: 150 },
    { id: 'description', header: 'Description', value: (r) => r.description, width: 280 },
    { id: 'class', header: 'Class', value: (r) => r.class, width: 90 },
    { id: 'branch', header: 'Branch', value: (r) => r.branch, width: 80 },
    { id: 'amount', header: 'Depreciation', type: 'money', value: (r) => r.amount, total: true },
    { id: 'accumulated', header: 'Accumulated after', type: 'money', value: (r) => r.accumulated },
    { id: 'nbv', header: 'Net book value after', type: 'money', value: (r) => r.nbv },
];
const runColumns: DataColumn<RunRow>[] = [
    { id: 'period', header: 'Month', value: (r) => r.period, width: 160 },
    { id: 'assets', header: 'Assets', type: 'number', value: (r) => r.assets, width: 90 },
    { id: 'total', header: 'Depreciation', type: 'money', value: (r) => r.total },
    { id: 'posted_by', header: 'Posted by', value: (r) => r.posted_by, width: 180, muted: true },
    { id: 'posted_at', header: 'Posted at', value: (r) => formatDateTime(r.posted_at), width: 180, muted: true },
];
</script>

<template>
    <AppLayout help="assets" title="Monthly depreciation">
        <div class="grid max-w-[1100px] gap-4">
            <div>
                <Breadcrumb :base="[{ label: 'Fixed assets', href: '/fixed-assets' }]" />
                <PageHeader title="Monthly depreciation">
                    <SelectInput :model-value="period?.id ?? ''" :options="monthOptions" class="w-56" aria-label="Month" @update:model-value="choose" />
                    <StatusBadge v-if="period" :status="period.status" />
                    <button v-if="canPost" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="post">Post depreciation</button>
                </PageHeader>
            </div>
            <p v-if="period" class="text-ui text-ink-2" role="status">
                <template v-if="preview.length">{{ preview.length }} asset(s) to depreciate for the month ending {{ formatDate(period.ends) }}: {{ formatMoney(total) }} BDT. Nothing is posted until you post it.</template>
                <template v-else-if="posted.length">Depreciation for {{ period.label }} is posted: {{ posted.map((r) => `${r.assets} asset(s), ${formatMoney(r.total)} BDT`).join('; ') }}.</template>
                <template v-else>Nothing to depreciate for {{ period.label }}.</template>
                <template v-if="!can.post && preview.length"> The finance manager posts it.</template>
            </p>
            <div v-if="preview.length" class="border border-line">
                <DataTable id="depreciation-preview" label="Depreciation for the month" :columns="previewColumns" :rows="preview" :row-key="(r) => r.asset_id" currency="BDT" :url-sync="false" compact-toolbar
                    empty-text="Nothing to depreciate for this month." />
            </div>
            <section v-if="runs.length">
                <h2 class="mb-2 text-ui font-medium">Posted batches</h2>
                <div class="border border-line">
                    <DataTable id="depreciation-runs" label="Posted batches" :columns="runColumns" :rows="runs" :row-key="(r) => `${r.period_id}-${r.posted_at}`" currency="BDT" :url-sync="false"
                        :open-on-click="false" compact-toolbar empty-text="No depreciation posted yet." />
                </div>
            </section>
        </div>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
