<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { serverPage } from '@/lib/paging';
import { formatDate, formatMoney } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';
const currency = useEntityCurrency();

interface ClaimRow { id: string; number: string; status: string; loss_date: string; reported_on: string; reserve: string; policy_number: string | null; policyholder: string }
const props = defineProps<{ filters: { status: string }; statuses: string[]; claims: { data: ClaimRow[]; current_page: number; last_page: number; total: number } }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const columns: DataColumn<ClaimRow>[] = [
    { id: 'number', header: 'Claim', value: (c) => c.number, href: (c) => `/claims/${c.id}`, width: 150 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, width: 150, muted: true },
    { id: 'policyholder', header: 'Policyholder', value: (c) => c.policyholder, width: 200 },
    { id: 'loss_date', header: 'Loss', type: 'date', value: (c) => c.loss_date },
    { id: 'reported_on', header: 'Reported', type: 'date', value: (c) => c.reported_on },
    { id: 'reserve', header: 'Incurred', type: 'money', value: (c) => c.reserve, total: true }, // gap audit GA-36: the reserve set, kept after closing
    { id: 'status', header: 'Status', type: 'status', value: (c) => c.status, filterOptions: props.statuses },
];
</script>

<template>
    <AppLayout help="claims" title="Claims" fill>
        <QueueView data-tour="claims-queue"
            id="claims"
            v-model:active="active"
            title="Claims"
            :columns="columns"
            :rows="claims.data"
            :page="serverPage(claims)"
            :row-key="(c) => c.id"
            :currency="currency"
            empty-text="No claims yet."
            :empty-action="can('claim.register') ? { label: 'Register a claim', href: '/claims/create' } : null"
            :action="can('claim.register') ? { label: 'Register a claim', href: '/claims/create' } : null"
            :inspector-title="(c) => c.number"
            :inspector-subtitle="(c) => `${c.policy_number ?? ''} · ${c.policyholder}`"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Date of loss', value: formatDate(row.loss_date) }, { label: 'Reported on', value: formatDate(row.reported_on) }, { label: 'Incurred', value: `${formatMoney(row.reserve, currency)}`, num: true }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/claims/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the claim</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
