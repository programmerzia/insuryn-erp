<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useOnboarding } from '@/lib/onboarding';
import { usePermissions } from '@/lib/permissions';

interface PolicyRow { id: string; number: string | null; status: string; inception: string; expiry: string; policyholder: string; product_code: string; gross_premium: string }
const props = defineProps<{ filters: { status: string; search: string }; statuses: string[]; policies: { data: PolicyRow[]; current_page: number; last_page: number; total: number } }>();

const { can } = usePermissions();
const onboarding = useOnboarding();
const active = ref<string | null>(null);
const columns: DataColumn<PolicyRow>[] = [
    { id: 'number', header: 'Policy', value: (p) => p.number ?? 'Quote', href: (p) => `/policies/${p.id}`, width: 160 },
    { id: 'policyholder', header: 'Policyholder', value: (p) => p.policyholder, width: 220 },
    { id: 'product', header: 'Product', value: (p) => p.product_code, width: 96, filterOptions: undefined },
    { id: 'inception', header: 'Starts', type: 'date', value: (p) => p.inception },
    { id: 'expiry', header: 'Ends', type: 'date', value: (p) => p.expiry },
    { id: 'premium', header: 'Gross premium', type: 'money', value: (p) => p.gross_premium, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status, filterOptions: props.statuses },
];
</script>

<template>
    <AppLayout help="policies" title="Policies" fill>
        <QueueView
            id="policies"
            v-model:active="active"
            title="Policies"
            :columns="columns"
            :rows="policies.data"
            :row-key="(p) => p.id"
            currency="BDT"
            selectable
            :empty-text="onboarding.setupNeeded ? 'No policies yet: a product has to be set up first.' : 'No policies yet.'"
            :empty-action="onboarding.setupNeeded && onboarding.canSetup ? { label: 'Set up a product', href: '/setup?step=product' } : can('policy.create') ? { label: 'New quote', href: '/policies/create' } : null"
            :action="can('policy.create') ? { label: 'New quote', href: '/policies/create' } : null"
            :inspector-title="(p) => p.number ?? 'Quote'"
            :inspector-subtitle="(p) => p.policyholder"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Product', value: row.product_code }, { label: 'Cover', value: `${formatDate(row.inception)} to ${formatDate(row.expiry)}` }, { label: 'Gross premium', value: `${formatMoney(row.gross_premium)} BDT`, num: true }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/policies/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the policy</Link>
            </template>
        </QueueView>
    </AppLayout>
</template>
