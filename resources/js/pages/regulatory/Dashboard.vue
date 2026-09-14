<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** Market gap G5, Regulatory dashboard: the solvency snapshot (placeholder formula, verify), the latest quarter's returns and the technical provisions run. */
defineProps<{
    quarter: { key: string; label: string };
    solvency: { as_of: string; available: string; required: string; minimum_capital: string; premium_basis: string; premium_component: string; claims_basis: string; claims_component: string;
        ratio: string; meets: boolean; premium_factor: string; claims_factor: string };
    returns: { code: string; title: string; status: string; filed_on: string | null; filing_reference: string | null }[];
    provisions: { number: string | null; quarter: string; status: string; total_ibnr: string } | null;
}>();
</script>

<template>
    <AppLayout help="reports" title="Regulatory dashboard">
        <div class="mx-auto grid max-w-6xl gap-4 px-4 py-4">
            <header class="flex flex-wrap items-baseline gap-3">
                <h1 class="text-section font-semibold">Regulatory dashboard</h1>
                <span class="text-ui text-ink-2">Insurance Development and Regulatory Authority (IDRA)</span>
                <nav class="ml-auto flex gap-4 text-ui">
                    <Link href="/regulatory/returns" class="text-accent-text hover:underline">Returns</Link>
                    <Link href="/regulatory/provisions" class="text-accent-text hover:underline">Technical provisions</Link>
                </nav>
            </header>

            <section class="border border-line bg-surface p-4" aria-labelledby="solvency-title">
                <div class="flex flex-wrap items-baseline gap-3">
                    <h2 id="solvency-title" class="text-ui font-medium">Solvency snapshot</h2>
                    <span class="text-dense text-ink-2">as of {{ formatDate(solvency.as_of) }}</span>
                    <span class="ml-auto text-dense text-warn">Placeholder formula — verify with IDRA's solvency rules</span>
                </div>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-dense text-ink-2">Available capital (assets − liabilities)</p>
                        <p class="text-xl font-semibold tabular-nums">{{ formatMoney(solvency.available) }}</p>
                    </div>
                    <div>
                        <p class="text-dense text-ink-2">Required capital</p>
                        <p class="text-xl font-semibold tabular-nums">{{ formatMoney(solvency.required) }}</p>
                    </div>
                    <div>
                        <p class="text-dense text-ink-2">Solvency ratio</p>
                        <p class="text-xl font-semibold tabular-nums" :class="solvency.meets ? 'text-ok' : 'text-danger'">{{ solvency.ratio }}</p>
                        <p class="text-dense" :class="solvency.meets ? 'text-ok' : 'text-danger'">{{ solvency.meets ? 'Meets the requirement' : 'Below the requirement' }}</p>
                    </div>
                </div>
                <dl class="mt-4 grid gap-x-6 gap-y-1 border-t border-line pt-3 text-dense sm:grid-cols-3">
                    <div class="flex justify-between gap-2"><dt class="text-ink-2">Minimum paid-up capital</dt><dd class="tabular-nums">{{ formatMoney(solvency.minimum_capital) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-2">{{ solvency.premium_factor }} of net premium {{ formatMoney(solvency.premium_basis) }}</dt><dd class="tabular-nums">{{ formatMoney(solvency.premium_component) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-2">{{ solvency.claims_factor }} of claims incurred {{ formatMoney(solvency.claims_basis) }}</dt><dd class="tabular-nums">{{ formatMoney(solvency.claims_component) }}</dd></div>
                </dl>
                <p class="mt-2 text-dense text-ink-2">Amounts in BDT. Premium and claims over the last twelve months; required capital is the greatest of the three.</p>
            </section>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="border border-line bg-surface p-4" aria-labelledby="returns-title">
                    <div class="flex items-baseline gap-2">
                        <h2 id="returns-title" class="text-ui font-medium">Returns · {{ quarter.label }}</h2>
                        <Link :href="`/regulatory/returns?period=${quarter.key}`" class="ml-auto text-ui text-accent-text hover:underline">Open</Link>
                    </div>
                    <ul class="mt-2">
                        <li v-for="form in returns" :key="form.code" class="flex items-center gap-3 border-b border-line py-2 text-ui last:border-b-0">
                            <Link :href="`/regulatory/returns?period=${quarter.key}&form=${form.code}`" class="min-w-0 flex-1 truncate hover:underline">{{ form.title }}</Link>
                            <span v-if="form.status === 'filed'" class="text-dense text-ink-2">{{ formatDate(form.filed_on) }} · {{ form.filing_reference }}</span>
                            <StatusBadge :status="form.status" />
                        </li>
                    </ul>
                </section>
                <section class="border border-line bg-surface p-4" aria-labelledby="provisions-title">
                    <div class="flex items-baseline gap-2">
                        <h2 id="provisions-title" class="text-ui font-medium">Technical provisions</h2>
                        <Link href="/regulatory/provisions" class="ml-auto text-ui text-accent-text hover:underline">Open</Link>
                    </div>
                    <dl v-if="provisions" class="mt-2 grid gap-2 text-ui">
                        <div class="flex justify-between"><dt class="text-ink-2">Latest run</dt><dd>{{ provisions.number ?? 'Draft' }} · {{ provisions.quarter }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-2">Status</dt><dd><StatusBadge :status="provisions.status" /></dd></div>
                        <div class="flex justify-between"><dt class="text-ink-2">IBNR provision</dt><dd class="tabular-nums font-medium">{{ formatMoney(provisions.total_ibnr) }} BDT</dd></div>
                    </dl>
                    <p v-else class="mt-2 text-ui text-ink-2">No technical provisions run yet. Prepare the quarter's run to set the IBNR provision.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
