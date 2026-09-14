<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** Reinsurance → Cessions: every cession movement per reinsurer (issue, endorsement, cancellation, facultative placement), and the risks above treaty capacity. */
interface CessionRow {
    id: string; kind: string; movement: string; date: string; share: string; ceded_sum_insured: string; premium: string; commission: string; policy_id: string; policy_number: string;
    insured: string; reinsurer: string; treaty: string | null;
}
const props = defineProps<{ cessions: CessionRow[]; aboveCapacity: { policy_id: string; policy_number: string; insured: string; sum_insured: string; above_capacity: string }[]; currency: string }>();

const active = ref<string | null>(null);
const columns: DataColumn<CessionRow>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (c) => c.date, width: 110 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, href: (c) => `/policies/${c.policy_id}?tab=reinsurance`, width: 170 },
    { id: 'insured', header: 'Insured', value: (c) => c.insured, width: 180 },
    { id: 'reinsurer', header: 'Reinsurer', value: (c) => c.reinsurer, width: 220, filterOptions: [...new Set(props.cessions.map((c) => c.reinsurer))] },
    { id: 'kind', header: 'Basis', value: (c) => c.kind, width: 130, filterOptions: ['SBC compulsory', 'Quota share', 'Surplus', 'Facultative'] },
    { id: 'movement', header: 'Movement', value: (c) => c.movement, width: 110, filterOptions: ['Issue', 'Endorsement', 'Cancellation', 'Placement', 'Backfill'] },
    { id: 'share', header: 'Share %', value: (c) => c.share, width: 80 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (c) => c.ceded_sum_insured },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (c) => c.premium, total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (c) => c.commission, total: true },
];
</script>

<template>
    <AppLayout title="Cessions" fill>
        <div v-if="aboveCapacity.length" class="border-b border-line bg-surface-2 px-6 py-2 text-ui" role="status">
            <span class="font-medium">Above treaty capacity:</span>
            <template v-for="(p, i) in aboveCapacity" :key="p.policy_id">
                <span v-if="i > 0"> · </span>
                <Link :href="`/policies/${p.policy_id}?tab=reinsurance`" class="text-accent-text hover:underline">{{ p.policy_number }}</Link>
                {{ p.insured }}, {{ formatMoney(p.above_capacity) }} {{ currency }} to place facultatively
            </template>
        </div>
        <QueueView
            id="ri-cessions"
            v-model:active="active"
            title="Cessions"
            :columns="columns"
            :rows="cessions"
            :row-key="(c) => c.id"
            :currency="currency"
            empty-text="No cessions yet. Policies are ceded when they are issued under a treaty."
            :action="{ label: 'Treaties', href: '/reinsurance/treaties' }"
            :inspector-title="(c) => `${c.policy_number} · ${c.reinsurer}`"
            :inspector-subtitle="(c) => `${c.kind} · ${c.movement}`"
        >
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Date', value: formatDate(row.date) }, { label: 'Treaty', value: row.treaty ?? 'Facultative' }, { label: 'Share', value: `${row.share}%` },
                    { label: 'Ceded sum insured', value: `${formatMoney(row.ceded_sum_insured)} ${currency}`, num: true },
                    { label: 'Ceded premium', value: `${formatMoney(row.premium)} ${currency}`, num: true }, { label: 'Commission', value: `${formatMoney(row.commission)} ${currency}`, num: true },
                ]" />
                <Link :href="`/policies/${row.policy_id}?tab=reinsurance`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the policy's reinsurance</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
