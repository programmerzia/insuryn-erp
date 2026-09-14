<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

/** Design addendum v2 §B.7 monthly depreciation batch: choose a month, preview each asset's charge, post (journal preview first). Posting a month again posts nothing. */
const props = defineProps<{
    periods: { id: string; label: string; status: string }[];
    period: { id: string; label: string; status: string; ends: string } | null;
    preview: { asset_id: string; number: string; description: string; class: string; branch: string; amount: string; accumulated: string; nbv: string }[];
    total: string;
    runs: { period: string; period_id: string; assets: number; total: string; posted_at: string; posted_by: string }[];
    can: { post: boolean };
}>();
const confirm = useJournalConfirm();
const posted = computed(() => props.runs.filter((r) => r.period_id === props.period?.id));

function choose(event: Event): void {
    router.get('/fixed-assets/depreciation', { period: (event.target as HTMLSelectElement).value }, { preserveState: false });
}
function post(): void {
    if (!props.period) return;
    void confirm.request(`/fixed-assets/depreciation/${props.period.id}`, {}, `Post depreciation for ${props.period.label}?`, `Post ${formatMoney(props.total)} BDT`);
}
</script>

<template>
    <AppLayout title="Monthly depreciation">
        <div class="grid max-w-[1040px] gap-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="text-title font-semibold">Monthly depreciation</h1>
                <select class="h-8 rounded-control border border-line-control bg-surface px-2 text-body text-ink" aria-label="Month" :value="period?.id" @change="choose">
                    <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.label }}{{ p.status === 'open' ? '' : ` (${p.status.replace('_', ' ')})` }}</option>
                </select>
                <StatusBadge v-if="period" :status="period.status" />
                <button v-if="can.post && preview.length && period?.status === 'open'" type="button" class="ml-auto inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="post">
                    Post depreciation
                </button>
                <Link href="/fixed-assets" class="text-ui text-accent-text hover:underline" :class="{ 'ml-auto': !(can.post && preview.length && period?.status === 'open') }">Fixed assets</Link>
            </header>
            <p v-if="period" class="text-ui text-ink-2">
                <template v-if="preview.length">{{ preview.length }} asset(s) to depreciate for the month ending {{ formatDate(period.ends) }}: {{ formatMoney(total) }} BDT. Nothing is posted until you post it.</template>
                <template v-else-if="posted.length">Depreciation for {{ period.label }} is posted: {{ posted.map((r) => `${r.assets} asset(s), ${formatMoney(r.total)} BDT`).join('; ') }}.</template>
                <template v-else>Nothing to depreciate for {{ period.label }}.</template>
                <template v-if="!can.post && preview.length"> The finance manager posts it.</template>
            </p>
            <div v-if="preview.length" class="overflow-x-auto rounded-panel border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2">
                        <tr class="h-(--row-h)">
                            <th class="border-b border-line px-3 text-left font-medium">Asset</th><th class="border-b border-line px-3 text-left font-medium">Description</th>
                            <th class="border-b border-line px-3 text-left font-medium">Class</th><th class="border-b border-line px-3 text-left font-medium">Branch</th>
                            <th class="border-b border-line px-3 text-right font-medium">Depreciation (BDT)</th><th class="border-b border-line px-3 text-right font-medium">Accumulated after</th><th class="border-b border-line px-3 text-right font-medium">Net book value after</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in preview" :key="row.asset_id" class="h-(--row-h) hover:bg-surface-2">
                            <td class="border-b border-line px-3"><Link :href="`/fixed-assets/${row.asset_id}`" class="text-accent-text hover:underline">{{ row.number }}</Link></td>
                            <td class="border-b border-line px-3">{{ row.description }}</td><td class="border-b border-line px-3">{{ row.class }}</td><td class="border-b border-line px-3">{{ row.branch }}</td>
                            <td class="num border-b border-line px-3">{{ formatMoney(row.amount) }}</td><td class="num border-b border-line px-3">{{ formatMoney(row.accumulated) }}</td><td class="num border-b border-line px-3">{{ formatMoney(row.nbv) }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="h-(--row-h) bg-surface-2 font-medium"><td colspan="4" class="px-3">Total</td><td class="num px-3">{{ formatMoney(total) }}</td><td colspan="2" /></tr></tfoot>
                </table>
            </div>
            <section v-if="runs.length">
                <h2 class="mb-2 text-ui font-medium">Posted batches</h2>
                <ul class="rounded-panel border border-line text-ui">
                    <li v-for="(run, i) in runs" :key="i" class="flex gap-4 border-b border-line px-3 py-2 last:border-b-0">
                        <span class="w-32">{{ run.period }}</span><span class="w-28 text-ink-2">{{ run.assets }} asset(s)</span><span class="num w-32">{{ formatMoney(run.total) }}</span><span class="text-ink-2">{{ run.posted_by }} · {{ formatDate(run.posted_at) }}</span>
                    </li>
                </ul>
            </section>
        </div>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
