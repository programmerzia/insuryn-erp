<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { type Paginated, serverPage } from '@/lib/paging';
import { CESSION_BASES, CESSION_MOVEMENTS, type CessionRow } from '@/lib/reinsurance';

/** Reinsurance → Cessions: every cession movement per reinsurer (issue, endorsement, cancellation, facultative placement), and the risks above treaty capacity. Pages on the server. */
const props = defineProps<{ cessions: Paginated<CessionRow>; reinsurers: string[]; aboveCapacity: { policy_id: string; policy_number: string; insured: string; sum_insured: string; above_capacity: string }[]; currency: string }>();

const active = ref<string | null>(null);
const columns: DataColumn<CessionRow>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (c) => c.date, width: 110 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, href: (c) => `/policies/${c.policy_id}?tab=reinsurance`, width: 170 },
    { id: 'insured', header: 'Policyholder', value: (c) => c.insured, width: 180 },
    { id: 'reinsurer', header: 'Reinsurer', value: (c) => c.reinsurer, width: 220, filterOptions: props.reinsurers },
    { id: 'treaty', header: 'Treaty', value: (c) => c.treaty ?? 'Facultative', href: (c) => (c.treaty_id ? `/reinsurance/treaties/${c.treaty_id}` : null), width: 140 },
    { id: 'kind', header: 'Basis', value: (c) => c.kind, width: 130, filterOptions: CESSION_BASES },
    { id: 'movement', header: 'Movement', value: (c) => c.movement, width: 110, filterOptions: CESSION_MOVEMENTS },
    { id: 'share', header: 'Share %', type: 'number', value: (c) => c.share, width: 80 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (c) => c.ceded_sum_insured },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (c) => c.premium, total: true },
    { id: 'commission', header: 'Commission', type: 'money', value: (c) => c.commission, total: true },
];
</script>

<template>
    <AppLayout help="reinsurance" title="Cessions" fill>
        <div v-if="aboveCapacity.length" class="border-b border-line bg-surface-2 px-6 py-2 text-ui max-sm:px-4" role="status">
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
            :rows="cessions.data"
            :page="serverPage(cessions)"
            :row-key="(c) => c.id"
            :currency="currency"
            empty-text="No cessions yet. Policies are ceded when they are issued under a treaty."
            :empty-action="{ label: 'Open treaties', href: '/reinsurance/treaties' }"
            :inspector-title="(c) => `${c.policy_number} · ${c.reinsurer}`"
            :inspector-subtitle="(c) => `${c.kind} · ${c.movement}`"
        >
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Date', value: formatDate(row.date) }, { label: 'Policyholder', value: row.insured }, { label: 'Treaty', value: row.treaty ?? 'Facultative' }, { label: 'Share', value: `${row.share}%` },
                    { label: 'Ceded sum insured', value: `${formatMoney(row.ceded_sum_insured)} ${currency}`, num: true },
                    { label: 'Ceded premium', value: `${formatMoney(row.premium)} ${currency}`, num: true }, { label: 'Commission', value: `${formatMoney(row.commission)} ${currency}`, num: true },
                ]" />
                <div class="mt-4 flex flex-col gap-1 text-ui">
                    <Link :href="`/policies/${row.policy_id}?tab=reinsurance`" class="text-accent-text hover:underline">Open the policy's reinsurance</Link>
                    <Link v-if="row.treaty_id" :href="`/reinsurance/treaties/${row.treaty_id}`" class="text-accent-text hover:underline">Open the treaty</Link>
                </div>
            </template>
        </QueueView>
    </AppLayout>
</template>
