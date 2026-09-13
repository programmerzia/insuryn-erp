<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import DateRangeFilter from '@/components/forms/DateRangeFilter.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { drillFrom, startTrail } from '@/lib/drill';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney } from '@/lib/money';
import type { EntityRef, TrialBalanceRow } from '@/types/accounting';

/**
 * UX brief §6.6 trial balance: a collapsible tree (account type → accounts) with debits, credits, the balance and the previous month end
 * beside it; any figure drills to the account's activity, then its journals, the event and the source document.
 */
const props = defineProps<{ entity: EntityRef; asOf: string; rows: TrialBalanceRow[]; totals: { debit: string; credit: string; balanced: boolean }; compare?: { asOf: string; balances: Record<string, string> } }>();

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'] as const;
const collapsed = reactive<Record<string, boolean>>({});
const sum = (values: (string | undefined)[]) => values.reduce((total, v) => total + (parseMoney(v) ?? 0n), 0n);
const groups = computed(() =>
    TYPES.map((type) => {
        const rows = props.rows.filter((r) => r.type === type);
        return {
            type, rows,
            debit: formatMinor(sum(rows.map((r) => r.debit))), credit: formatMinor(sum(rows.map((r) => r.credit))),
            balance: formatMinor(sum(rows.map((r) => r.balance))), previous: formatMinor(sum(rows.map((r) => props.compare?.balances[r.accountId]))),
        };
    }).filter((g) => g.rows.length > 0),
);
const change = (row: TrialBalanceRow) => formatMinor((parseMoney(row.balance) ?? 0n) - (parseMoney(props.compare?.balances[row.accountId]) ?? 0n));
const activity = (row: TrialBalanceRow) => `/reports/account-activity?account_id=${row.accountId}&to=${props.asOf}`;
const title = (type: string) => ({ asset: 'Assets', liability: 'Liabilities', equity: 'Equity', income: 'Income', expense: 'Expenses' })[type] ?? type;
startTrail();
</script>

<template>
    <AppLayout title="Trial balance" fill>
        <div class="flex h-11 items-center gap-3 border-b border-line px-4">
            <h1 class="text-section font-semibold">Trial balance</h1>
            <DateRangeFilter url="/accounting/trial-balance" :as-of="asOf" />
            <span class="ml-2 text-ui text-ink-2">{{ entity.name }} · local book · compared with {{ formatDate(compare?.asOf) }}</span>
            <span class="ml-auto inline-flex items-center gap-1.5 text-ui" :class="totals.balanced ? 'text-ok' : 'text-danger'" role="status">
                <span class="size-1.5 rounded-full" :class="totals.balanced ? 'bg-ok' : 'bg-danger'" aria-hidden="true" />{{ totals.balanced ? 'Balanced' : 'Out of balance' }}
            </span>
        </div>
        <div class="min-h-0 flex-1 overflow-auto">
            <table class="table-fixed border-separate border-spacing-0 text-dense" style="width: max(1000px, 100%)" aria-label="Trial balance">
                <colgroup><col style="width: 360px" /><col style="width: 140px" /><col style="width: 140px" /><col style="width: 150px" /><col style="width: 150px" /><col style="width: 140px" /><col /></colgroup>
                <thead class="sticky top-0 z-10 bg-surface-2 text-ink-2">
                    <tr class="h-(--row-h)">
                        <th class="border-b border-line px-4 text-left font-medium">Account</th>
                        <th class="border-b border-line px-3 text-right font-medium">Debit ({{ entity.currency }})</th>
                        <th class="border-b border-line px-3 text-right font-medium">Credit ({{ entity.currency }})</th>
                        <th class="border-b border-line px-3 text-right font-medium">Balance {{ formatDate(asOf) }}</th>
                        <th class="border-b border-line px-3 text-right font-medium">Balance {{ formatDate(compare?.asOf) }}</th>
                        <th class="border-b border-line px-3 text-right font-medium">Change</th>
                        <th class="border-b border-line" />
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.type">
                    <tr class="h-(--row-h) bg-surface font-medium">
                        <th scope="rowgroup" class="border-b border-line px-2 text-left">
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-control px-2 py-0.5 hover:bg-surface-2" :aria-expanded="!collapsed[group.type]" @click="collapsed[group.type] = !collapsed[group.type]">
                                <component :is="collapsed[group.type] ? ChevronRight : ChevronDown" :size="14" :stroke-width="1.5" aria-hidden="true" />{{ title(group.type) }}
                                <span class="font-normal text-ink-2">· {{ group.rows.length }}</span>
                            </button>
                        </th>
                        <td class="num border-b border-line px-3">{{ group.debit }}</td>
                        <td class="num border-b border-line px-3">{{ group.credit }}</td>
                        <td class="num border-b border-line px-3">{{ group.balance }}</td>
                        <td class="num border-b border-line px-3 text-ink-2">{{ group.previous }}</td>
                        <td class="border-b border-line" /><td class="border-b border-line" />
                    </tr>
                    <template v-if="!collapsed[group.type]">
                        <tr v-for="row in group.rows" :key="row.accountId" class="h-(--row-h) hover:bg-surface-2">
                            <td class="truncate border-b border-line pr-3 pl-10"><span class="text-ink-2 tabular-nums">{{ row.code }}</span> {{ row.name }}</td>
                            <td class="num border-b border-line px-3"><Link prefetch="hover" :href="activity(row)" class="hover:underline" @click="drillFrom('Trial balance')">{{ formatMoney(row.debit) }}</Link></td>
                            <td class="num border-b border-line px-3"><Link prefetch="hover" :href="activity(row)" class="hover:underline" @click="drillFrom('Trial balance')">{{ formatMoney(row.credit) }}</Link></td>
                            <td class="num border-b border-line px-3"><Link prefetch="hover" :href="activity(row)" class="font-medium hover:text-accent-text hover:underline" @click="drillFrom('Trial balance')">{{ formatMoney(row.balance) }}</Link></td>
                            <td class="num border-b border-line px-3 text-ink-2">{{ formatMoney(compare?.balances[row.accountId] ?? '0.00') }}</td>
                            <td class="num border-b border-line px-3 text-ink-2">{{ change(row) }}</td>
                            <td class="border-b border-line" />
                        </tr>
                    </template>
                </tbody>
                <tbody v-if="rows.length === 0"><tr><td colspan="7" class="px-4 py-10 text-center text-ui text-ink-2">No posted journals up to {{ formatDate(asOf) }}.</td></tr></tbody>
                <tfoot class="sticky bottom-0 bg-surface-2">
                    <tr class="h-(--row-h) font-medium">
                        <td class="border-t border-line px-4">Total</td>
                        <td class="num border-t border-line px-3">{{ formatMoney(totals.debit) }}</td>
                        <td class="num border-t border-line px-3">{{ formatMoney(totals.credit) }}</td>
                        <td class="border-t border-line" colspan="4" />
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
