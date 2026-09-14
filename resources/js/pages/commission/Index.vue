<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/**
 * GA-10 (D-75): commission history, read-only. Every approved or paid commission statement, whichever run made it, and the Phase 1 commission plans that still
 * pay producers of products without a compensation scheme (A-20, A-192). Statements are prepared, approved and paid only in Commission statements.
 */
interface Plan { id: string; code: string; name: string; rate_percent: string; withholding: string | null; status: string }
interface Statement { id: string; number: string; agent_code: string; agent_name: string; agent_id: string; up_to: string; period_end: string | null; gross: string; withholding: string; advances: string; net: string; status: string; paid_via: string; approved_on: string | null; paid_on: string | null }
defineProps<{ plans: Plan[]; statements: Statement[]; statementsTotal?: number; can: { run: boolean } }>();

const view = ref<'statements' | 'plans'>('statements');
const active = ref<string | null>(null);
const routeWords: Record<string, string> = { bank: 'Bank', payroll: 'Payroll', ap: 'Accounts payable' };
const monthOf = (s: Statement) => s.period_end ?? s.up_to;
const statementColumns: DataColumn<Statement>[] = [
    { id: 'number', header: 'Statement', value: (s) => s.number, width: 150 },
    { id: 'producer', header: 'Producer', value: (s) => s.agent_code, href: (s) => `/distribution/producers/${s.agent_id}`, width: 100 },
    { id: 'name', header: 'Name', value: (s) => s.agent_name, width: 160, muted: true },
    { id: 'up_to', header: 'Earned up to', type: 'date', value: (s) => s.up_to },
    { id: 'gross', header: 'Gross', type: 'money', value: (s) => s.gross, total: true },
    { id: 'withholding', header: 'Tax withheld', type: 'money', value: (s) => s.withholding, total: true },
    { id: 'net', header: 'Net', type: 'money', value: (s) => s.net, total: true },
    { id: 'route', header: 'Paid through', value: (s) => routeWords[s.paid_via] ?? s.paid_via, width: 130 },
    { id: 'status', header: 'Status', type: 'status', value: (s) => s.status, filterOptions: ['approved', 'paid'] },
    { id: 'paid_on', header: 'Paid on', type: 'date', value: (s) => s.paid_on },
];
const planColumns: DataColumn<Plan>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, width: 110 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 240 },
    { id: 'rate', header: 'Rate (%)', type: 'number', value: (p) => p.rate_percent, width: 100 },
    { id: 'withholding', header: 'Withholding', value: (p) => p.withholding ?? 'None', width: 160, muted: true },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status },
];
</script>

<template>
    <AppLayout help="commission" title="Commission history" fill>
        <div class="flex h-9 items-end gap-4 border-b border-line px-4" role="tablist" aria-label="Commission history">
            <button v-for="tab in [{ id: 'statements', label: 'Statements' }, { id: 'plans', label: 'Plans (Phase 1)' }] as const" :key="tab.id" type="button" role="tab" :aria-selected="view === tab.id"
                class="-mb-px h-8 border-b-2 text-ui" :class="view === tab.id ? 'border-accent text-ink' : 'border-transparent text-ink-2 hover:text-ink'" @click="view = tab.id; active = null">
                {{ tab.label }}
            </button>
        </div>
        <QueueView
            v-if="view === 'statements'"
            id="commission-history"
            v-model:active="active"
            title="Commission history"
            :columns="statementColumns"
            :rows="statements"
            :total="statementsTotal ?? null"
            :row-key="(s) => s.id"
            currency="BDT"
            empty-text="No commission statement has been approved yet."
            :empty-action="{ label: 'Open commission statements', href: '/distribution/statements' }"
            :inspector-title="(s) => s.number"
            :inspector-subtitle="(s) => `${s.agent_code} · earned up to ${formatDate(s.up_to)}`"
        >
            <template #toolbar>
                <Link href="/distribution/statements" class="text-ui text-accent-text hover:underline">{{ can.run ? 'Approve or pay in Commission statements' : 'Open Commission statements' }}</Link>
            </template>
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Status' }, { label: 'Gross', value: `${formatMoney(row.gross)} BDT`, num: true }, { label: 'Tax withheld', value: `${formatMoney(row.withholding)} BDT`, num: true }, { label: 'Advances recovered', value: `${formatMoney(row.advances)} BDT`, num: true }, { label: 'Net', value: `${formatMoney(row.net)} BDT`, num: true }, { label: 'Paid through', value: routeWords[row.paid_via] ?? row.paid_via }, { label: 'Approved on', value: formatDate(row.approved_on) }, { label: 'Paid on', value: formatDate(row.paid_on) }]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div class="mt-4 flex flex-col gap-2">
                    <Link v-if="row.status === 'approved'" :href="`/distribution/statements?period_end=${monthOf(row)}`" class="text-ui text-accent-text hover:underline">Pay it in Commission statements</Link>
                    <Link :href="`/commission/agents/${row.agent_id}`" class="text-ui text-accent-text hover:underline">Producer's commission entries</Link>
                </div>
            </template>
        </QueueView>
        <DataTable v-else id="commission-plans" label="Commission plans" :columns="planColumns" :rows="plans" :row-key="(p) => p.id" :url-sync="false" empty-text="No Phase 1 commission plans.">
            <template #toolbar>
                <h1 class="mr-3 text-section font-semibold">Commission plans</h1>
                <span class="text-ui text-ink-2">Read-only. New commission terms are set up as <Link href="/distribution/schemes" class="text-accent-text hover:underline">compensation schemes</Link>.</span>
            </template>
        </DataTable>
    </AppLayout>
</template>
