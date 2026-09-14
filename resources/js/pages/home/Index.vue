<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowRight, Plus, Route } from 'lucide-vue-next';
import { computed } from 'vue';
import PinLink from '@/components/shell/PinLink.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney } from '@/lib/money';
import { useOnboarding } from '@/lib/onboarding';
import { savePreference, usePreferences } from '@/lib/preferences';
import { tourAction, tourSteps } from '@/lib/tour';
import type { SharedProps } from '@/types/shared';
import { Link } from '@inertiajs/vue3';

/** UX brief §5: the role's work queues top to bottom — title, count, top five rows, "Open queue". No KPI cards; charts only for cash. */
interface Queue {
    key: string;
    title: string;
    href: string | null;
    empty: string;
    emptyAction: { label: string; href: string };
    count: number;
    columns: { id: string; label: string; type: 'text' | 'money' | 'date' | 'status' }[];
    rows: { href: string | null; cells: Record<string, string | null> }[];
    progress?: { done: number; total: number };
    cash?: { balance: string; currency: string; days: { date: string; net: string }[] };
}

/** Flow fix X4: work started from Home (New quote, Record a receipt, Register a claim, New manual journal), for the user's permissions. */
const props = defineProps<{ queues: Queue[]; starts: { label: string; href: string }[] }>();
const page = usePage<SharedProps>();
const currency = computed(() => page.props.shell?.entity?.currency ?? 'BDT');
const preferences = usePreferences();
const onboarding = useOnboarding();
// Session S4: start the guided tour, or resume it where it was ended.
const tourLabel = computed(() => preferences.tour?.status === 'dismissed' ? `Resume the tour (step ${preferences.tour.step + 1} of ${tourSteps.length})`
    : preferences.tour?.status === 'finished' ? 'Take the tour again' : 'Take the guided tour');
function startTour(): void {
    savePreference('tour', tourAction(preferences.tour, preferences.tour?.status === 'dismissed' ? 'resume' : 'start'), 0);
}
const waiting = computed(() => props.queues.reduce((sum, q) => sum + (q.cash ? 0 : q.count), 0));

function cell(type: string, value: string | null | undefined): string {
    if (value === null || value === undefined) return '';
    // GA-08: accounting event codes are shown in plain words.
    return type === 'money' ? formatMoney(value) : type === 'date' ? formatDate(value) : type === 'event' ? eventLabel(value) : value;
}

/** 30-day bars on one scale: bar heights from BigInt minor units (no floats in the amounts), scaled to 40px. */
function bars(days: { date: string; net: string }[]): { date: string; net: string; height: number; negative: boolean }[] {
    const values = days.map((d) => parseMoney(d.net) ?? 0n);
    const max = values.reduce((m, v) => (v < 0n ? -v : v) > m ? (v < 0n ? -v : v) : m, 0n);
    return days.map((d, i) => {
        const v = values[i] ?? 0n;
        const abs = v < 0n ? -v : v;
        return { date: d.date, net: formatMinor(v), negative: v < 0n, height: max === 0n ? 0 : Number((abs * 40n) / max) };
    });
}
</script>

<template>
    <AppLayout title="Home">
        <div class="grid max-w-[1040px] gap-5" data-tour="home-queues">
            <header class="grid grid-cols-[1fr_auto] items-start gap-x-4">
                <h1 class="text-title font-semibold">Home</h1>
                <button v-if="preferences.tour?.status !== 'active'" type="button" class="row-span-2 inline-flex h-8 items-center gap-1.5 rounded-control border border-line-control px-3 text-ui text-ink hover:bg-surface-2" @click="startTour">
                    <Route :size="16" :stroke-width="1.5" aria-hidden="true" />{{ tourLabel }}
                </button>
                <p class="text-ui text-ink-2">
                    <template v-if="queues.length === 0">Nothing is assigned to your roles here. Use Ctrl+K to find a record or a page.</template>
                    <template v-else-if="waiting === 0">Nothing needs your action right now.</template>
                    <template v-else><span class="tabular-nums">{{ waiting }}</span> {{ waiting === 1 ? 'item needs' : 'items need' }} your action.</template>
                </p>
                <nav v-if="starts.length" aria-label="Start work" class="col-span-2 mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-ui">
                    <Link v-for="start in starts" :key="start.href" :href="start.href" class="inline-flex items-center gap-1 text-accent-text hover:underline">
                        <Plus :size="14" :stroke-width="1.5" aria-hidden="true" />{{ start.label }}
                    </Link>
                </nav>
            </header>

            <section v-if="onboarding.setupNeeded && onboarding.canSetup" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-panel border border-line bg-surface-2 px-4 py-3 text-ui" aria-label="Setup">
                <p>No products are set up yet, so policies cannot be issued.</p>
                <Link href="/setup" class="font-medium text-accent-text hover:underline">Continue setup</Link>
            </section>
            <p v-if="onboarding.demoCommand" class="text-ui text-ink-2">Want to look around first? Run <code class="rounded-control bg-surface-2 px-1">{{ onboarding.demoCommand }}</code> and sign in at nonlife.localhost to walk through a week in a non-life insurer.</p>

            <section v-for="queue in queues" :key="queue.key" class="rounded-panel border border-line" :aria-labelledby="`queue-${queue.key}`">
                <div class="flex h-11 items-center gap-3 border-b border-line bg-surface-2 px-4">
                    <h2 :id="`queue-${queue.key}`" class="text-ui font-semibold">{{ queue.title }}</h2>
                    <span v-if="!queue.cash" class="num inline-flex items-center gap-1.5 text-ui" :class="queue.count ? 'text-ink' : 'text-ink-2'">
                        <span v-if="queue.count" class="size-1.5 rounded-full bg-accent" aria-hidden="true" />{{ queue.count }}
                    </span>
                    <span v-if="queue.progress" class="text-ui text-ink-2 tabular-nums">{{ queue.progress.done }} of {{ queue.progress.total }} tasks done</span>
                    <Link v-if="queue.href" :href="queue.href" class="ml-auto inline-flex items-center gap-1 text-ui text-accent-text hover:underline">
                        Open queue <ArrowRight :size="14" :stroke-width="1.5" aria-hidden="true" />
                    </Link>
                </div>

                <div v-if="queue.cash" class="flex flex-wrap items-end gap-8 px-4 py-3">
                    <div>
                        <p class="text-dense text-ink-2">In the bank ledger accounts ({{ queue.cash.currency }})</p>
                        <p class="num text-left text-title font-semibold">{{ formatMoney(queue.cash.balance) }}</p>
                    </div>
                    <figure class="min-w-0 flex-1">
                        <div class="flex h-20 items-center gap-px" role="img" :aria-label="`Daily net bank movement for the last 30 days, from ${formatDate(queue.cash.days[0]?.date)} to ${formatDate(queue.cash.days[queue.cash.days.length - 1]?.date)}`">
                            <div v-for="bar in bars(queue.cash.days)" :key="bar.date" class="flex h-full min-w-0 flex-1 flex-col" :title="`${formatDate(bar.date)}: ${bar.net}`">
                                <div class="flex flex-1 items-end"><div v-if="!bar.negative" class="w-full bg-ok" :style="{ height: `${bar.height}px` }" /></div>
                                <div class="h-px bg-line" />
                                <div class="flex flex-1 items-start"><div v-if="bar.negative" class="w-full bg-danger" :style="{ height: `${bar.height}px` }" /></div>
                            </div>
                        </div>
                        <figcaption class="flex justify-between text-dense text-ink-2"><span>{{ formatDate(queue.cash.days[0]?.date) }}</span><span>Daily net movement</span><span>{{ formatDate(queue.cash.days[queue.cash.days.length - 1]?.date) }}</span></figcaption>
                    </figure>
                </div>

                <div v-else-if="queue.rows.length === 0" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-ui">
                    <p class="text-ink-2">{{ queue.empty }}</p>
                    <Link :href="queue.emptyAction.href" class="text-accent-text hover:underline">{{ queue.emptyAction.label }}</Link>
                </div>

                <table v-else class="w-full table-fixed border-separate border-spacing-0 text-dense">
                    <thead>
                        <tr class="h-8">
                            <th v-for="column in queue.columns" :key="column.id" class="border-b border-line px-4 font-medium whitespace-nowrap text-ink-2" :class="column.type === 'money' ? 'w-36 text-right' : 'text-left'">
                                {{ column.label }}<template v-if="column.type === 'money'"> ({{ currency }})</template>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in queue.rows" :key="index" class="h-(--row-h) hover:bg-surface-2">
                            <td
                                v-for="(column, c) in queue.columns"
                                :key="column.id"
                                class="truncate border-b border-line px-4 group-last:border-0"
                                :class="[column.type === 'money' ? 'num w-36' : '', c === 0 ? 'text-ink' : 'text-ink-2', index === queue.rows.length - 1 ? 'border-b-0' : '']"
                            >
                                <StatusBadge v-if="column.type === 'status' && row.cells[column.id]" :status="row.cells[column.id]!" />
                                <PinLink v-else-if="c === 0 && row.href" :href="row.href" :title="row.cells[column.id] ?? queue.title" class="text-accent-text hover:underline">{{ cell(column.type, row.cells[column.id]) }}</PinLink>
                                <template v-else>{{ cell(column.type, row.cells[column.id]) }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="!queue.cash && queue.count > queue.rows.length" class="border-t border-line px-4 py-2 text-dense text-ink-2">
                    Showing {{ queue.rows.length }} of <span class="tabular-nums">{{ queue.count }}</span>.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
