<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Inspector from '@/components/shell/Inspector.vue';
import SplitPane from '@/components/shell/SplitPane.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { type DataColumn, DataTable } from '@/components/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { serverPage } from '@/lib/paging';
import { formatDate, formatMoney } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';
const currency = useEntityCurrency();

// A reader without receipt.create (the auditor, the finance manager) sees who records receipts instead of a link that ends in a 403.
const { can } = usePermissions();
const canRecord = can('receipt.create');

interface ReceiptRow {
    id: string;
    number: string;
    channel: string;
    amount: string;
    value_date: string;
    reference: string | null;
    status: string;
    agent_collection: boolean;
}

const props = defineProps<{ receipts: { data: ReceiptRow[]; current_page: number; last_page: number; total: number } }>();

const active = ref<string | null>(null);
const selected = computed(() => props.receipts.data.find((r) => r.id === active.value) ?? null);
const channel = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());

const columns: DataColumn<ReceiptRow>[] = [
    { id: 'number', header: 'Number', value: (r) => r.number, href: (r) => `/receipts/${r.id}`, width: 150 },
    { id: 'value_date', header: 'Value date', type: 'date', value: (r) => r.value_date },
    { id: 'channel', header: 'Channel', value: (r) => channel(r.channel) + (r.agent_collection ? ' (agent)' : ''), width: 150, filterOptions: undefined },
    { id: 'reference', header: 'Reference', value: (r) => r.reference, width: 200, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['allocated', 'partially_allocated', 'unallocated', 'bounced'] },
];
</script>

<template>
    <AppLayout help="receipts" title="Receipts" fill>
        <SplitPane id="receipts" :open="selected !== null">
            <DataTable
                id="receipts"
                v-model:active="active"
                label="Receipts"
                :columns="columns"
                :rows="receipts.data"
                :page="serverPage(receipts)"
                :row-key="(r) => r.id"
                :currency="currency"
                selectable
                empty-text="No receipts yet."
                :empty-action="canRecord ? { label: 'Record a receipt', href: '/receipts/create' } : null"
                :empty-hint="canRecord ? null : 'Only the branch officer, branch manager or accountant can record a receipt.'"
                export-name="receipts"
                @close="active = null"
            >
                <template #toolbar>
                    <h1 class="mr-3 text-section font-semibold">Receipts</h1>
                    <Link v-if="canRecord" href="/receipts/create" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Record a receipt</Link>
                    <span v-else class="text-ui text-ink-2" data-testid="permission-hint">Only the branch officer, branch manager or accountant can record a receipt.</span>
                    <Link href="/cheques" class="ml-2 text-ui text-accent-text hover:underline">Cheque register</Link>
                </template>
                <template #bulk="{ rows }">
                    <span class="text-ui text-ink-2">Export or open {{ rows.length === 1 ? 'the receipt' : 'each receipt' }} to allocate it.</span>
                </template>
            </DataTable>
            <template #inspector>
                <Inspector v-if="selected" :title="selected.number" :subtitle="`${channel(selected.channel)} · ${formatDate(selected.value_date)}`" @close="active = null">
                    <template #details>
                        <dl class="grid grid-cols-[8rem_1fr] gap-y-2 text-ui">
                            <dt class="text-ink-2">Status</dt><dd><StatusBadge :status="selected.status" /></dd>
                            <dt class="text-ink-2">Amount</dt><dd class="num text-left">{{ formatMoney(selected.amount) }} {{ currency }}</dd>
                            <dt class="text-ink-2">Reference</dt><dd>{{ selected.reference ?? 'None' }}</dd>
                            <dt class="text-ink-2">Collected by</dt><dd>{{ selected.agent_collection ? 'An agent' : 'The company' }}</dd>
                        </dl>
                        <Link :href="`/receipts/${selected.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the receipt</Link>
                    </template>
                </Inspector>
            </template>
        </SplitPane>
    </AppLayout>
</template>
