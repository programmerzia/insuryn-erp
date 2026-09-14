<script setup lang="ts">
import { ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

interface Entry { id: string; earned_on: string; kind: string; policy_number: string | null; base: string; rate_percent: string | null; amount: string; withholding: string; net: string; status: string }
const props = defineProps<{ agent: { id: string; code: string }; from: string; to: string; statement: { opening_payable: string; closing_payable: string; entries: Entry[]; totals: { earned: string; clawback: string; withholding: string; net: string } } }>();

const active = ref<string | null>(null);
const words = (v: string) => v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const columns: DataColumn<Entry>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (e) => e.earned_on },
    { id: 'kind', header: 'Kind', value: (e) => words(e.kind), width: 110, filterOptions: undefined },
    { id: 'policy', header: 'Policy', value: (e) => e.policy_number, width: 160 },
    { id: 'base', header: 'Premium received', type: 'money', value: (e) => e.base },
    { id: 'rate', header: 'Rate (%)', type: 'number', value: (e) => e.rate_percent, width: 90 },
    { id: 'amount', header: 'Commission', type: 'money', value: (e) => e.amount, total: true },
    { id: 'withholding', header: 'Withheld', type: 'money', value: (e) => e.withholding, total: true },
    { id: 'net', header: 'Net', type: 'money', value: (e) => e.net, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (e) => e.status },
];
void props;
</script>

<template>
    <AppLayout help="commission" :title="`Agent ${agent.code} commission`" fill>
        <div class="border-b border-line px-4 pt-2"><Breadcrumb :base="[{ label: 'Commission', href: '/commission' }]" /></div>
        <DataTable id="commission-entries" v-model:active="active" :label="`Commission for agent ${agent.code}`" :columns="columns" :rows="statement.entries" :row-key="(e) => e.id" currency="BDT" :url-sync="false" empty-text="No commission in this period.">
            <template #toolbar>
                <h1 class="mr-2 shrink-0 text-section font-semibold">Agent {{ agent.code }}</h1>
                <DateRangeFilter :url="`/commission/agents/${agent.id}`" :from="from" :to="to" />
                <span class="ml-3 text-ui whitespace-normal text-ink-2">Payable at the start <span class="tabular-nums text-ink">{{ formatMoney(statement.opening_payable) }}</span> · at the end <span class="tabular-nums text-ink">{{ formatMoney(statement.closing_payable) }}</span> · clawed back <span class="tabular-nums text-ink">{{ formatMoney(statement.totals.clawback) }}</span></span>
            </template>
        </DataTable>
    </AppLayout>
</template>
