<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AuditRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { type Paginated, serverPage } from '@/lib/paging';
import type { CessionRow } from '@/lib/reinsurance';

/** The treaty page (market gap G4): its terms, the reinsurers that share it, the cessions written under it, timeline and audit. Editing opens the treaty editor. */
interface Participant { id: string; code: string; name: string; share: string; rating: string | null; country: string; status: string }
const props = defineProps<{
    treaty: { id: string; code: string; name: string; class: string; year: number; period_from: string; period_to: string; type: string; status: string; cession: string | null; retention: string | null;
        lines: number | null; capacity: string | null; commission: string; sbc_share: string; ceded_premium: string; ceded_commission: string };
    participants: Participant[];
    cessions: Paginated<CessionRow>;
    canManage: boolean;
    currency: string;
    timeline?: TimelineEntry[];
    audit?: AuditRow[];
}>();

const TYPES: Record<string, string> = { quota_share: 'Quota share', surplus: 'Surplus' };
const facts = computed(() => [
    { label: 'Class', value: props.treaty.class },
    { label: 'Underwriting year', value: String(props.treaty.year) },
    { label: 'Type', value: TYPES[props.treaty.type] ?? props.treaty.type },
    { label: `Ceded premium (${props.currency})`, value: formatMoney(props.treaty.ceded_premium), num: true },
    { label: `Commission (${props.currency})`, value: formatMoney(props.treaty.ceded_commission), num: true },
]);
const terms = computed(() => [
    { label: 'Covers policies starting', value: `${formatDate(props.treaty.period_from)} to ${formatDate(props.treaty.period_to)}` },
    { label: 'Type', value: TYPES[props.treaty.type] ?? props.treaty.type },
    ...(props.treaty.type === 'quota_share'
        ? [{ label: 'Cession', value: `${props.treaty.cession}% of each risk after the SBC share` }]
        : [
              { label: `Retention per risk (${props.currency})`, value: formatMoney(props.treaty.retention), num: true },
              { label: 'Lines', value: String(props.treaty.lines ?? '') },
              { label: `Treaty capacity (${props.currency})`, value: formatMoney(props.treaty.capacity), num: true },
          ]),
    { label: 'Reinsurance commission', value: `${props.treaty.commission}% of ceded premium` },
    { label: 'SBC compulsory share', value: `${props.treaty.sbc_share}%` },
]);
const participantColumns: DataColumn<Participant>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, width: 90 },
    { id: 'name', header: 'Reinsurer', value: (p) => p.name, width: 260 },
    { id: 'share', header: 'Share %', type: 'number', value: (p) => p.share, width: 90 },
    { id: 'rating', header: 'Rating', value: (p) => p.rating ?? '—', width: 140 },
    { id: 'country', header: 'Country', value: (p) => p.country, width: 80 },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status },
];
const cessionColumns: DataColumn<CessionRow>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (c) => c.date, width: 110 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, href: (c) => `/policies/${c.policy_id}?tab=reinsurance`, width: 170 },
    { id: 'insured', header: 'Policyholder', value: (c) => c.insured, width: 180 },
    { id: 'reinsurer', header: 'Reinsurer', value: (c) => c.reinsurer, width: 200 },
    { id: 'movement', header: 'Movement', value: (c) => c.movement, width: 110, filterOptions: ['Issue', 'Endorsement', 'Cancellation', 'Placement', 'Backfill'] },
    { id: 'share', header: 'Share %', type: 'number', value: (c) => c.share, width: 80 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (c) => c.ceded_sum_insured, width: 170 },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (c) => c.premium, total: true, width: 170 },
    { id: 'commission', header: 'Commission', type: 'money', value: (c) => c.commission, total: true, width: 170 },
];
</script>

<template>
    <AppLayout help="reinsurance" :title="`Treaty ${treaty.code}`">
        <ObjectPage
            :title="`Treaty ${treaty.code}`"
            :subtitle="treaty.name"
            :status="treaty.status"
            :facts="facts"
            :crumbs="[{ label: 'Treaties', href: '/reinsurance/treaties' }]"
            :currency="currency"
            :timeline="timeline"
            :audit="audit"
            :hidden-tabs="['accounting', 'documents']"
            transactions-label="Cessions"
        >
            <template #actions>
                <Link v-if="canManage" :href="`/reinsurance/treaties/${treaty.id}/edit`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Edit treaty</Link>
            </template>
            <template #overview>
                <div class="grid max-w-[1100px] gap-6">
                    <section class="max-w-[640px]">
                        <h2 class="mb-2 text-section font-semibold">Terms</h2>
                        <DetailList :items="terms" />
                        <p class="mt-2 text-dense text-ink-2">SBC's compulsory share is ceded first; this treaty applies to the rest. Changes apply to policies ceded from then on; cessions already written stay.</p>
                    </section>
                    <section>
                        <h2 class="mb-2 text-section font-semibold">Reinsurers on this treaty</h2>
                        <DataTable id="ri-treaty-participants" label="Reinsurers on this treaty" :columns="participantColumns" :rows="participants" :row-key="(p) => p.id" :url-sync="false"
                            :open-on-click="false" compact-toolbar empty-text="No reinsurers on this treaty yet." />
                    </section>
                </div>
            </template>
            <template #transactions>
                <DataTable id="ri-treaty-cessions" label="Cessions" :columns="cessionColumns" :rows="cessions.data" :page="serverPage(cessions)" :row-key="(c) => c.id" :currency="currency"
                    :url-sync="false" :open-on-click="false" compact-toolbar empty-text="No cessions under this treaty yet. Policies of its class are ceded when they are issued." />
            </template>
        </ObjectPage>
    </AppLayout>
</template>
