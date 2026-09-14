<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { eventLabel } from '@/lib/events';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

/**
 * Market gap G5, Regulatory → Technical provisions: the quarter's run workbench. Per class: unearned premium, IBNR by percentage of net premium and by the paid
 * chain ladder (when there is enough history), the method used, the prior quarter's provision and the movement; the paid triangle per class; the premium
 * deficiency check; and the journal the run posts. The finance manager prepares (recalculates with a method per class) and marks it reviewed; the CFO approves and
 * posts it after seeing the journal.
 */
interface ClassLine { class: string; label: string; net_premium_base: string; percentage_rate: string; percentage_ibnr: string; chain_ladder_available: boolean; chain_ladder_ibnr: string;
    paid_to_date: string; ultimate: string; case_reserves: string; method: string; fell_back: boolean; ibnr: string; prior_ibnr: string; movement: string; upr: string;
    expected_loss_ratio: string; maintenance: string; expected_cost: string; deficiency: string; deficiency_note: string }
interface Triangle { class: string; label: string; available: boolean; accident_quarters_with_paid: number; dev_labels: string[]; factors: string[];
    rows: { accident_quarter: string; cells: (string | null)[]; paid_to_date: string; ultimate: string; unpaid: string }[]; unpaid: string }
const props = defineProps<{
    quarter: { key: string; label: string; end: string };
    quarters: { value: string; label: string }[];
    run: { id: string; number: string | null; status: string; prepared: string; reviewed: string | null; posted: string | null; prepared_by_me: boolean; sod_blocked: boolean } | null;
    classes: ClassLine[];
    triangles: Triangle[];
    totals: { ibnr: string; upr: string; prior: string };
    journal: { event: string; class: string; debit: string; credit: string; amount: string }[];
    notes: string[];
    minQuarters: number;
    can: { run: boolean; approve: boolean };
}>();

const methods = reactive<Record<string, string>>(Object.fromEntries(props.classes.map((c) => [c.class, c.method])));
const triangleClass = ref(props.triangles.find((t) => t.available)?.class ?? props.triangles[0]?.class ?? '');
const triangle = computed(() => props.triangles.find((t) => t.class === triangleClass.value) ?? null);
const editable = computed(() => props.can.run && props.run?.status !== 'posted');
const changed = computed(() => props.classes.some((c) => methods[c.class] !== c.method));
const confirm = useJournalConfirm();

function pickQuarter(value: string): void {
    router.get('/regulatory/provisions', { quarter: value }, { preserveState: false });
}
function prepare(): void {
    router.post('/regulatory/provisions/prepare', { quarter: props.quarter.key, methods: { ...methods } }, { preserveScroll: true });
}
function review(): void {
    if (props.run) router.post(`/regulatory/provisions/${props.run.id}/review`, {}, { preserveScroll: true });
}
function approve(): void {
    if (props.run) void confirm.request(`/regulatory/provisions/${props.run.id}/approve`, {}, `Approve and post the technical provisions for ${props.quarter.label}?`, `Post ${formatMoney(props.totals.ibnr)} BDT IBNR`);
}
</script>

<template>
    <AppLayout help="reports" title="Technical provisions" fill>
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2">
            <h1 class="mr-2 text-section font-semibold">Technical provisions</h1>
            <label class="sr-only" for="provisions-quarter">Quarter</label>
            <SelectInput id="provisions-quarter" :model-value="quarter.key" class="w-60" :options="quarters" @update:model-value="(v) => pickQuarter(String(v))" />
            <template v-if="run">
                <span class="text-ui text-ink-2">{{ run.number }}</span>
                <StatusBadge :status="run.status" />
            </template>
            <span v-else class="text-ui text-ink-2">Not prepared — the figures below are a live calculation.</span>
            <div class="ml-auto flex items-center gap-2">
                <Link href="/regulatory" class="text-ui text-accent-text hover:underline">Dashboard</Link>
                <Button v-if="editable" :variant="run ? 'secondary' : 'primary'" @click="prepare">{{ run ? (changed ? 'Recalculate with these methods' : 'Recalculate') : 'Prepare run' }}</Button>
                <Button v-if="can.run && run?.status === 'draft'" @click="review">Mark reviewed</Button>
                <Button v-if="can.approve && run?.status === 'reviewed' && !run.sod_blocked" @click="approve">Approve and post</Button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-auto px-4 py-4">
            <p v-if="run" class="mb-3 flex flex-wrap gap-4 text-dense text-ink-2">
                <span>Prepared by {{ run.prepared }}</span>
                <span v-if="run.reviewed">Reviewed by {{ run.reviewed }}</span>
                <span v-if="run.posted">Approved and posted by {{ run.posted }}, dated {{ formatDate(quarter.end) }}</span>
                <span v-if="run.status === 'reviewed' && run.sod_blocked" class="text-warn">You prepared or reviewed this run, so someone else approves it.</span>
            </p>

            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <div class="border border-line bg-surface p-3"><p class="text-dense text-ink-2">Unearned premium reserve at {{ formatDate(quarter.end) }}</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(totals.upr) }}</p></div>
                <div class="border border-line bg-surface p-3"><p class="text-dense text-ink-2">IBNR provision this quarter</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(totals.ibnr) }}</p></div>
                <div class="border border-line bg-surface p-3"><p class="text-dense text-ink-2">Prior quarter's IBNR (released on posting)</p><p class="text-lg font-semibold tabular-nums">{{ formatMoney(totals.prior) }}</p></div>
            </div>

            <section class="mb-5">
                <h2 class="mb-1 text-ui font-medium">IBNR by class <span class="font-normal text-ink-2">(BDT)</span></h2>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full text-ui">
                        <thead>
                            <tr class="border-b border-line bg-surface-2 text-ink-2">
                                <th scope="col" class="px-2 py-1.5 text-left font-normal">Class</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Unearned premium</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Net premium (12 months)</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">IBNR at rate</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Paid to date</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Open case reserves</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">IBNR by chain ladder</th>
                                <th scope="col" class="px-2 py-1.5 text-left font-normal">Method</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">IBNR</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Prior quarter</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-normal">Movement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in classes" :key="line.class" class="border-b border-line last:border-b-0">
                                <td class="px-2 py-1.5">{{ line.label }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.upr) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.net_premium_base) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.percentage_ibnr) }} <span class="text-ink-2">at {{ line.percentage_rate }}</span></td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.paid_to_date) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.case_reserves) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    <template v-if="line.chain_ladder_available">{{ formatMoney(line.chain_ladder_ibnr) }}</template>
                                    <span v-else class="text-ink-2">Not enough history</span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <select v-if="editable" v-model="methods[line.class]" class="h-7 rounded-control border border-line-control bg-surface px-1 text-ui" :aria-label="`Method for ${line.label}`">
                                        <option value="percentage">Percentage</option>
                                        <option value="chain_ladder" :disabled="!line.chain_ladder_available">Chain ladder</option>
                                    </select>
                                    <span v-else>{{ line.method === 'chain_ladder' ? 'Chain ladder' : 'Percentage' }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-right font-medium tabular-nums">{{ formatMoney(line.ibnr) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.prior_ibnr) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(line.movement) }}</td>
                            </tr>
                            <tr v-if="classes.length === 0"><td colspan="11" class="px-2 py-3 text-ink-2">No premium or claims for this quarter.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-1 text-dense text-ink-2">The chain ladder needs paid claims in at least {{ minQuarters }} accident quarters; otherwise the class uses the percentage. Rates are placeholders to verify.</p>
            </section>

            <section v-if="triangle" class="mb-5">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-ui font-medium">Paid claims triangle <span class="font-normal text-ink-2">(cumulative, BDT, by accident quarter and development quarter)</span></h2>
                    <div class="ml-auto flex gap-1" role="tablist">
                        <button v-for="t in triangles" :key="t.class" type="button" role="tab" :aria-selected="t.class === triangleClass" class="h-7 rounded-control border px-2 text-ui"
                            :class="t.class === triangleClass ? 'border-accent text-accent-text' : 'border-line-control text-ink-2 hover:bg-surface-2'" @click="triangleClass = t.class">{{ t.label }}</button>
                    </div>
                </div>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full text-dense">
                        <thead>
                            <tr class="border-b border-line bg-surface-2 text-ink-2">
                                <th scope="col" class="px-2 py-1 text-left font-normal">Accident quarter</th>
                                <th v-for="dev in triangle.dev_labels" :key="dev" scope="col" class="px-2 py-1 text-right font-normal">{{ dev }}</th>
                                <th scope="col" class="px-2 py-1 text-right font-normal">Ultimate</th>
                                <th scope="col" class="px-2 py-1 text-right font-normal">Unpaid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in triangle.rows" :key="row.accident_quarter" class="border-b border-line">
                                <td class="px-2 py-1">{{ row.accident_quarter }}</td>
                                <td v-for="(cell, index) in row.cells" :key="index" class="px-2 py-1 text-right tabular-nums" :class="{ 'bg-surface-2': cell === null }">{{ cell === null ? '' : formatMoney(cell) }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ formatMoney(row.ultimate) }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ formatMoney(row.unpaid) }}</td>
                            </tr>
                            <tr class="bg-surface-2 text-ink-2">
                                <td class="px-2 py-1">Development factor</td>
                                <td v-for="(factor, index) in triangle.factors" :key="index" class="px-2 py-1 text-right tabular-nums">{{ factor }}</td>
                                <td class="px-2 py-1 text-right">—</td>
                                <td class="px-2 py-1 text-right" />
                                <td class="px-2 py-1 text-right font-medium text-ink tabular-nums">{{ formatMoney(triangle.unpaid) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-1 text-dense text-ink-2">{{ triangle.available ? `Paid claims in ${triangle.accident_quarters_with_paid} accident quarters.` : `Only ${triangle.accident_quarters_with_paid} accident quarters with paid claims: the percentage method applies.` }} Factor k takes development quarter k to k + 1.</p>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section>
                    <h2 class="mb-1 text-ui font-medium">Premium deficiency check</h2>
                    <ul class="grid gap-2">
                        <li v-for="line in classes" :key="line.class" class="border border-line p-2 text-ui">
                            <p :class="line.deficiency !== '0.00' ? 'text-danger' : ''">{{ line.deficiency_note }}</p>
                            <p class="text-dense text-ink-2">Unearned premium {{ formatMoney(line.upr) }}; expected claims and expenses {{ formatMoney(line.expected_cost) }} (loss ratio {{ line.expected_loss_ratio }} + expenses {{ line.maintenance }}).</p>
                        </li>
                    </ul>
                </section>
                <section>
                    <h2 class="mb-1 text-ui font-medium">Journal on posting <span class="font-normal text-ink-2">dated {{ formatDate(quarter.end) }}</span></h2>
                    <div class="overflow-x-auto border border-line">
                        <table class="w-full text-ui">
                            <thead>
                                <tr class="border-b border-line bg-surface-2 text-ink-2">
                                    <th scope="col" class="px-2 py-1.5 text-left font-normal">Event</th>
                                    <th scope="col" class="px-2 py-1.5 text-left font-normal">Class</th>
                                    <th scope="col" class="px-2 py-1.5 text-left font-normal">Debit</th>
                                    <th scope="col" class="px-2 py-1.5 text-left font-normal">Credit</th>
                                    <th scope="col" class="px-2 py-1.5 text-right font-normal">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(entry, index) in journal" :key="index" class="border-b border-line last:border-b-0">
                                    <td class="px-2 py-1.5">{{ eventLabel(entry.event) }}</td>
                                    <td class="px-2 py-1.5">{{ entry.class }}</td>
                                    <td class="px-2 py-1.5">{{ entry.debit }}</td>
                                    <td class="px-2 py-1.5">{{ entry.credit }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(entry.amount) }}</td>
                                </tr>
                                <tr v-if="journal.length === 0"><td colspan="5" class="px-2 py-3 text-ink-2">Nothing to post.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <ul class="mt-5 grid gap-1 text-dense text-ink-2">
                <li v-for="note in notes" :key="note">{{ note }}</li>
            </ul>
        </div>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
