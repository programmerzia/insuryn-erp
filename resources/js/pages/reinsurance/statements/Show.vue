<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AuditRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { type Paginated, serverPage } from '@/lib/paging';
import { CESSION_BASES, CESSION_MOVEMENTS, type CessionRow, type StatementRow } from '@/lib/reinsurance';

/** The reinsurer statement page (market gap G4): the quarter's account with the reinsurer, the bordereaux behind it, its cessions in the quarter, timeline and audit. */
const props = defineProps<{
    statement: StatementRow & { reinsurer_id: string; year: number; quarter_no: number };
    cessions: Paginated<CessionRow>;
    canPrepare: boolean;
    currency: string;
    timeline?: TimelineEntry[];
    audit?: AuditRow[];
}>();

const balanceWord = computed(() => (props.statement.due_to_reinsurer ? 'due to the reinsurer' : 'due from the reinsurer'));
const facts = computed(() => [
    { label: 'Quarter', value: props.statement.quarter },
    { label: `Premium ceded (${props.currency})`, value: formatMoney(props.statement.premium), num: true },
    { label: `Claims recoverable (${props.currency})`, value: formatMoney(props.statement.claims_recoverable), num: true },
    { label: `Closing balance (${props.currency})`, value: `${formatMoney(props.statement.closing)} ${balanceWord.value}`, num: true },
]);
const account = computed(() => [
    { label: 'Period', value: `${formatDate(props.statement.period_from)} to ${formatDate(props.statement.period_to)}` },
    { label: 'Opening balance', value: `${formatMoney(props.statement.opening)} ${props.currency}`, num: true },
    { label: 'Premium ceded', value: `${formatMoney(props.statement.premium)} ${props.currency}`, num: true },
    { label: 'Less commission', value: `${formatMoney(props.statement.commission)} ${props.currency}`, num: true },
    { label: 'Less claims recoverable', value: `${formatMoney(props.statement.claims_recoverable)} ${props.currency}`, num: true },
    { label: 'Closing balance', value: `${formatMoney(props.statement.closing)} ${props.currency} ${balanceWord.value}`, num: true },
    { label: 'Share of outstanding claims', value: `${formatMoney(props.statement.outstanding_claims_share)} ${props.currency}`, num: true },
]);
const columns: DataColumn<CessionRow>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (c) => c.date, width: 110 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, href: (c) => `/policies/${c.policy_id}?tab=reinsurance`, width: 170 },
    { id: 'insured', header: 'Policyholder', value: (c) => c.insured, width: 180 },
    { id: 'treaty', header: 'Treaty', value: (c) => c.treaty ?? 'Facultative', href: (c) => (c.treaty_id ? `/reinsurance/treaties/${c.treaty_id}` : null), width: 140 },
    { id: 'kind', header: 'Basis', value: (c) => c.kind, width: 130, filterOptions: CESSION_BASES },
    { id: 'movement', header: 'Movement', value: (c) => c.movement, width: 110, filterOptions: CESSION_MOVEMENTS },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (c) => c.ceded_sum_insured, width: 170 },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (c) => c.premium, total: true, width: 170 },
    { id: 'commission', header: 'Commission', type: 'money', value: (c) => c.commission, total: true, width: 170 },
];
function prepareAgain(): void {
    router.post('/reinsurance/statements', { reinsurer_id: props.statement.reinsurer_id, year: props.statement.year, quarter: props.statement.quarter_no }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout help="reinsurance" :title="statement.number">
        <ObjectPage
            :title="statement.number"
            :subtitle="`${statement.reinsurer} · ${statement.quarter}`"
            status="prepared"
            :facts="facts"
            :crumbs="[{ label: 'Reinsurer statements', href: '/reinsurance/statements' }]"
            :currency="currency"
            :timeline="timeline"
            :audit="audit"
            :hidden-tabs="['accounting', 'documents']"
            transactions-label="Cessions"
        >
            <template #actions>
                <Link :href="statement.bordereau" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2">Premium bordereau</Link>
                <Link :href="statement.claims_bordereau" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2">Claims bordereau</Link>
                <button v-if="canPrepare" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="prepareAgain">Prepare again</button>
            </template>
            <template #overview>
                <section class="max-w-[640px]">
                    <h2 class="mb-2 text-section font-semibold">Account for the quarter</h2>
                    <DetailList :items="account" />
                    <p class="mt-2 text-dense text-ink-2">Preparing the quarter again refreshes these figures; the statement number stays.</p>
                </section>
            </template>
            <template #transactions>
                <DataTable id="ri-statement-cessions" label="Cessions" :columns="columns" :rows="cessions.data" :page="serverPage(cessions)" :row-key="(c) => c.id" :currency="currency"
                    :url-sync="false" :open-on-click="false" compact-toolbar empty-text="No cessions to this reinsurer in the quarter." />
            </template>
        </ObjectPage>
    </AppLayout>
</template>
