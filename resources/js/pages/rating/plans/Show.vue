<script setup lang="ts">
import { Deferred, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import AuditList from '@/components/object/AuditList.vue';
import SkeletonRows from '@/components/object/SkeletonRows.vue';
import Timeline from '@/components/object/Timeline.vue';
import type { AuditRow, TimelineEntry } from '@/components/object/types';
import PlanDiff, { type Diff } from '@/components/rating/PlanDiff.vue';
import RateTableGrid, { type RateTable } from '@/components/rating/RateTableGrid.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';
import { formatAmount, formatHundredths, parseAmount, parseHundredths } from '@/lib/rating';
import { statusWord } from '@/lib/status';
import type { SharedProps } from '@/types/shared';

/**
 * Phase 3 design §6 tariff editor, one plan (slice R10a): Overview · Tables · Steps · Duties · Diff · Timeline · Audit. A draft is edited in place by a plan
 * manager; someone else approves it (maker ≠ checker); an approver activates it — refused while another active plan of the class covers its dates, unless it
 * supersedes that plan — and retires it. Every refusal comes back from the server with its reason and shows above the tabs.
 */
interface Plan { id: string; code: string; name: string; class_code: string; class_name: string; version: number; currency: string; effective_from: string; effective_to: string | null; status: string;
    source: string; verify: boolean; notes: string | null; copied_from: { id: string; version: number } | null; created_by_name: string | null; created_at: string | null; approved_by_name: string | null;
    approved_at: string | null; activated_by_name: string | null; activated_at: string | null; retired_by_name: string | null; retired_at: string | null }
interface Step { order_no: number; code: string; kind: string; expression: string; condition: string | null; applies_to: string | null; label_en: string; label_bn: string }
interface Duty { id: string; code: string; basis: string; rate_bp: number | null; amount_minor: number | null; bands: { from: number; to: number | null; amount_minor: number }[]; class_codes: string[];
    effective_from: string; effective_to: string | null; label_en: string; label_bn: string; verify: boolean; source: string | null; in_force: boolean }
const props = defineProps<{
    plan: Plan;
    tables: RateTable[];
    steps: Step[];
    problems: string[];
    duties: Duty[];
    versions: { id: string; version: number; status: string; effective_from: string; effective_to: string | null }[];
    compare_id: string | null;
    diff: Diff | null;
    overlaps: { id: string; code: string; version: number; effective_from: string; effective_to: string | null }[];
    classes: { code: string; name: string }[];
    step_kinds: string[];
    value_types: string[];
    today: string;
    can: { manage: boolean; edit: boolean; approve: boolean; approve_blocked: string | null; activate: boolean; retire: boolean; record_duties: boolean };
    timeline?: TimelineEntry[];
    audit?: AuditRow[];
}>();

const page = usePage<SharedProps>();
const base = `/rating/plans/${props.plan.id}`;
const title = computed(() => `${props.plan.code} v${props.plan.version}`);
const sourceWords: Record<string, string> = { idra_tariff: 'IDRA tariff', company: 'Company' };
const typeWords: Record<string, string> = { rate_pm: 'Rate per mille (‰)', rate_pct: 'Rate in percent (%)', flat: 'Flat amount', band: 'Bands (ranges with a label)' };
const basisWords: Record<string, string> = { pct_of_premium: 'Percent of net premium', flat_per_policy: 'Flat per policy', per_sum_insured_band: 'By sum insured band' };
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const inForce = (from: string, to: string | null) => `${formatDate(from)} ${to ? `to ${formatDate(to)}` : 'onwards'}`;
const copiedSlot = 'Copied from';
const when = (name: string | null, at: string | null) => (name ? `${name}${at ? `, ${formatDate(at)}` : ''}` : null);

const initialTab = typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('tab') ?? 'overview') : 'overview';
const tab = ref(initialTab);
watch(tab, (value) => {
    const params = new URLSearchParams(window.location.search);
    if (value === 'overview') params.delete('tab');
    else params.set('tab', value);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${params.toString() ? `?${params}` : ''}`);
});
const tabs = [['overview', 'Overview'], ['tables', 'Tables'], ['steps', 'Steps'], ['duties', 'Duties'], ['diff', 'Diff'], ['timeline', 'Timeline'], ['audit', 'Audit']] as const;

const facts = computed(() => [
    { label: 'Class', value: props.plan.class_name },
    { label: 'In force', value: inForce(props.plan.effective_from, props.plan.effective_to) },
    { label: 'Source', value: sourceWords[props.plan.source] ?? props.plan.source },
    { label: 'Tables', value: String(props.tables.length), num: true },
    { label: 'Steps', value: String(props.steps.length), num: true },
]);

// Header (draft only)
const header = useForm({ name: props.plan.name, effective_from: props.plan.effective_from, effective_to: props.plan.effective_to ?? '', source: props.plan.source, verify: props.plan.verify, notes: props.plan.notes ?? '' });
function saveHeader(): void {
    header.transform((data) => ({ ...data, effective_to: data.effective_to || null, notes: data.notes || null })).put(base, { preserveScroll: true, onSuccess: () => header.defaults() });
}

// Lifecycle
const drawer = ref<'version' | 'table' | 'step' | 'duty' | 'end-duty' | null>(null);
const done = () => (drawer.value = null);
const versionForm = useForm({ effective_from: '', effective_to: '' });
const overlapRefused = computed(() => page.props.errors?.reason === 'RATING_PLAN_OVERLAP');

async function approve(): Promise<void> {
    if (await confirmAction({ title: `Approve ${title.value}?`, body: 'Approval freezes the plan: its tables and steps cannot change after this. Activate it to put it in force.', confirmLabel: 'Approve plan' })) {
        router.post(`${base}/approve`, {}, { preserveScroll: true });
    }
}

async function activate(supersede: boolean): Promise<void> {
    const current = props.overlaps[0];
    const body = supersede && current
        ? `${current.code} v${current.version} is active for ${props.plan.class_name} ${inForce(current.effective_from, current.effective_to)}. It will end on ${formatDate(props.plan.effective_from)} and ${title.value} rates from that day.`
        : `${title.value} rates every ${props.plan.class_name.toLowerCase()} quote dated ${inForce(props.plan.effective_from, props.plan.effective_to)}.`;
    if (await confirmAction({ title: supersede ? `Activate ${title.value} and supersede ${current?.code ?? 'the current plan'}?` : `Activate ${title.value}?`, body, confirmLabel: supersede ? 'Activate and supersede' : 'Activate plan' })) {
        router.post(`${base}/activate`, { supersede }, { preserveScroll: true });
    }
}

async function retire(): Promise<void> {
    const body = props.plan.status === 'active' ? `Quotes dated ${inForce(props.plan.effective_from, props.plan.effective_to)} will find no ${props.plan.class_name.toLowerCase()} plan unless another is activated. Policies already rated keep their result.` : 'The approved plan will never be activated.';
    if (await confirmAction({ title: `Retire ${title.value}?`, body, confirmLabel: 'Retire plan', tone: 'danger' })) {
        router.post(`${base}/retire`, {}, { preserveScroll: true });
    }
}

async function deleteDraft(): Promise<void> {
    if (await confirmAction({ title: `Delete draft ${title.value}?`, body: 'The draft, its tables, rows and steps are deleted. Its audit trail stays.', confirmLabel: 'Delete draft', tone: 'danger' })) {
        router.delete(base);
    }
}

// Tables
const tableForm = useForm({ code: '', name: '', dimensions: '', value_type: 'rate_pm' });
function addTable(): void {
    tableForm.transform((data) => ({ ...data, dimensions: data.value_type === 'band' ? [] : data.dimensions.split(',').map((d) => d.trim()).filter(Boolean) }))
        .post(`${base}/tables`, { preserveScroll: true, onSuccess: () => { tableForm.reset(); done(); } });
}

// Steps
const editingStep = ref<string | null>(null);
const stepForm = useForm({ order_no: '' as string | number, code: '', kind: 'base', expression: '', condition: '', applies_to: '', label_en: '', label_bn: '' });
function openStep(step: Step | null): void {
    editingStep.value = step?.code ?? null;
    stepForm.clearErrors();
    const next = (props.steps.at(-1)?.order_no ?? 0) + 10;
    stepForm.defaults(step ? { ...step, condition: step.condition ?? '', applies_to: step.applies_to ?? '' } : { order_no: next, code: '', kind: 'base', expression: '', condition: '', applies_to: '', label_en: '', label_bn: '' });
    stepForm.reset();
    drawer.value = 'step';
}
function saveStep(): void {
    const options = { preserveScroll: true, onSuccess: done };
    const form = stepForm.transform((data) => ({ ...data, order_no: Number.parseInt(String(data.order_no), 10), condition: data.condition || null, applies_to: data.applies_to || null }));
    if (editingStep.value) form.put(`${base}/steps/${editingStep.value}`, options);
    else form.post(`${base}/steps`, options);
}
async function removeStep(step: Step): Promise<void> {
    if (await confirmAction({ title: `Remove step ${step.code}?`, body: `${step.label_en} leaves this draft.`, confirmLabel: 'Remove step', tone: 'danger' })) {
        router.delete(`${base}/steps/${step.code}`, { preserveScroll: true });
    }
}

// Duties
const dutyForm = useForm({ code: 'vat', basis: 'pct_of_premium', rate: '', amount: '', bands: [{ from: '0', to: '', amount: '' }] as { from: string; to: string; amount: string }[],
    class_codes: [props.plan.class_code], effective_from: '', effective_to: '', label_en: '', label_bn: '', verify: true });
const dutyErrors = ref<Record<string, string>>({});
function recordDuty(): void {
    const errors: Record<string, string> = {};
    const payload: Record<string, unknown> = { code: dutyForm.code, basis: dutyForm.basis, class_codes: dutyForm.class_codes, effective_from: dutyForm.effective_from, effective_to: dutyForm.effective_to || null,
        label_en: dutyForm.label_en, label_bn: dutyForm.label_bn, verify: dutyForm.verify };
    if (dutyForm.basis === 'pct_of_premium') {
        payload.rate_bp = parseHundredths(dutyForm.rate, '%');
        if (payload.rate_bp === null) errors.rate = 'Enter a percentage with at most two decimals, like 15.';
    } else if (dutyForm.basis === 'flat_per_policy') {
        payload.amount_minor = parseAmount(dutyForm.amount);
        if (payload.amount_minor === null) errors.amount = 'Enter an amount, like 50.00.';
    } else {
        payload.bands = dutyForm.bands.map((band, i) => {
            const from = parseAmount(band.from);
            const to = band.to.trim() === '' ? null : parseAmount(band.to);
            const amount = parseAmount(band.amount);
            if (from === null || amount === null || (band.to.trim() !== '' && to === null)) errors.bands = `Band ${i + 1}: enter the sums insured and the duty as amounts.`;
            return { from, to, amount_minor: amount };
        });
    }
    dutyErrors.value = errors;
    if (Object.keys(errors).length) return;
    dutyForm.transform(() => payload).post('/rating/duties', { preserveScroll: true, onSuccess: () => { dutyForm.reset(); done(); } });
}
const endingDuty = ref<Duty | null>(null);
const endForm = useForm({ effective_to: '' });
function dutyValue(duty: Duty): string {
    if (duty.basis === 'pct_of_premium') return `${formatHundredths(duty.rate_bp ?? 0)} %`;
    if (duty.basis === 'flat_per_policy') return `${formatAmount(duty.amount_minor)} ${props.plan.currency}`;
    return duty.bands.map((b) => `${formatAmount(b.amount_minor)} below ${b.to === null ? 'any' : formatAmount(b.to)}`).join('; ');
}

// Diff
const others = computed(() => props.versions.filter((v) => v.id !== props.plan.id));
const compareWith = ref(props.compare_id ?? '');
const compared = computed(() => props.versions.find((v) => v.id === props.compare_id) ?? null);
watch(compareWith, (id) => {
    if (id && id !== props.compare_id) router.get(base, { compare: id, tab: 'diff' }, { only: ['diff', 'compare_id'], preserveState: true, preserveScroll: true, replace: true });
});
</script>

<template>
    <AppLayout :title="title">
        <div class="flex min-h-full flex-col">
            <header class="border-b border-line px-6 pt-3">
                <Breadcrumb :base="[{ label: 'Tariffs', href: '/rating/plans' }]" />
                <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pb-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <h1 class="text-title font-semibold">{{ title }}</h1>
                            <StatusBadge :status="plan.status" />
                        </div>
                        <p class="truncate text-ui text-ink-2">{{ plan.name }}</p>
                    </div>
                    <dl class="flex flex-wrap gap-x-8 gap-y-1">
                        <div v-for="fact in facts" :key="fact.label">
                            <dt class="text-dense text-ink-2">{{ fact.label }}</dt>
                            <dd class="text-ui font-medium" :class="{ 'tabular-nums': fact.num }">{{ fact.value }}</dd>
                        </div>
                    </dl>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <Button v-if="can.manage" variant="secondary" @click="versionForm.reset(); drawer = 'version'">New version</Button>
                        <Button v-if="can.edit" variant="danger" @click="deleteDraft">Delete draft</Button>
                        <Button v-if="can.retire" variant="danger" @click="retire">Retire</Button>
                        <Button v-if="can.activate && overlaps.length" variant="secondary" @click="activate(false)">Activate</Button>
                        <Button v-if="can.activate" @click="activate(overlaps.length > 0)">{{ overlaps.length ? 'Activate and supersede' : 'Activate' }}</Button>
                        <Button v-if="can.approve" :disabled="can.approve_blocked !== null || problems.length > 0" :title="can.approve_blocked ?? (problems.length ? 'Fix what stops approval first.' : undefined)" @click="approve">Approve</Button>
                    </div>
                </div>
                <p v-if="can.approve && can.approve_blocked" class="-mt-1 pb-3 text-ui text-ink-2" role="note">{{ can.approve_blocked }}</p>
            </header>

            <div class="px-6 pt-4">
                <p v-if="plan.verify" class="mb-3 border-l-2 border-warn bg-surface-2 px-3 py-2 text-ui" role="status">Placeholder values — verify before use.</p>
                <FormBanner />
                <p v-if="overlapRefused && can.activate && overlaps.length === 0" class="mb-3 text-ui">Another plan became active for these dates. Refresh the page to see it.</p>
            </div>

            <TabsRoot v-model="tab" class="flex flex-1 flex-col">
                <TabsList class="flex gap-5 border-b border-line px-6" aria-label="Sections">
                    <TabsTrigger v-for="[value, label] in tabs" :key="value" :value="value" class="-mb-px h-9 border-b-2 border-transparent text-ui text-ink-2 hover:text-ink data-[state=active]:border-accent data-[state=active]:text-ink">
                        {{ label }}
                    </TabsTrigger>
                </TabsList>
                <div class="flex-1 px-6 py-4">
                    <TabsContent value="overview" class="grid max-w-[1100px] gap-8 outline-none lg:grid-cols-2">
                        <section class="grid content-start gap-4">
                            <div v-if="problems.length" class="border-l-2 border-warn pl-3" role="status">
                                <h2 class="mb-1 text-ui font-medium">Before this plan can be approved</h2>
                                <ul class="list-disc pl-5 text-ui"><li v-for="problem in problems" :key="problem">{{ problem }}</li></ul>
                            </div>
                            <div v-if="overlaps.length" class="border-l-2 border-warn pl-3 text-ui" role="status">
                                <p v-for="o in overlaps" :key="o.id">
                                    <Link :href="`/rating/plans/${o.id}`" class="text-accent-text hover:underline">{{ o.code }} v{{ o.version }}</Link> is active for {{ plan.class_name }} {{ inForce(o.effective_from, o.effective_to) }}.
                                    Only one plan of a class is active on a day: activating this one supersedes it, ending it on {{ formatDate(plan.effective_from) }}.
                                </p>
                            </div>
                            <h2 class="text-section font-semibold">Lifecycle</h2>
                            <DetailList :items="[
                                { label: 'Drafted by', value: when(plan.created_by_name, plan.created_at) },
                                { label: 'Approved by', value: when(plan.approved_by_name, plan.approved_at) },
                                { label: 'Activated by', value: when(plan.activated_by_name, plan.activated_at) },
                                { label: 'Retired by', value: when(plan.retired_by_name, plan.retired_at) },
                                { label: 'Copied from', value: plan.copied_from ? `v${plan.copied_from.version}` : null },
                                { label: 'Currency', value: plan.currency },
                            ]">
                                <template v-if="plan.copied_from" #[copiedSlot]><Link :href="`/rating/plans/${plan.copied_from.id}`" class="text-accent-text hover:underline">v{{ plan.copied_from.version }}</Link></template>
                            </DetailList>
                            <p v-if="plan.status !== 'draft'" class="text-ui text-ink-2">This plan is {{ statusWord(plan.status).toLowerCase() }} and cannot change. To change its rates, create a new version.</p>
                        </section>
                        <section>
                            <h2 class="mb-3 text-section font-semibold">Plan</h2>
                            <FormLayout v-if="can.edit" submit-label="Save plan" :dirty="header.isDirty" :processing="header.processing" @submit="saveHeader" @cancel="header.reset()">
                                <Field id="plan_name" label="Name" :error="header.errors.name"><TextInput v-model="header.name" /></Field>
                                <div class="grid grid-cols-2 gap-3">
                                    <Field id="plan_from" label="In force from" :error="header.errors.effective_from"><DateInput v-model="header.effective_from" /></Field>
                                    <Field id="plan_to" label="Until" optional :error="header.errors.effective_to"><DateInput v-model="header.effective_to" /></Field>
                                </div>
                                <Field id="plan_source" label="Source" :error="header.errors.source"><SelectInput id="plan_source" v-model="header.source" :options="Object.entries(sourceWords).map(([value, label]) => ({ value, label }))" /></Field>
                                <label class="flex items-center gap-2 text-ui"><input v-model="header.verify" type="checkbox" class="size-3.5 accent-accent" />Values are placeholders to verify</label>
                                <Field id="plan_notes" label="Notes" optional :error="header.errors.notes"><TextInput v-model="header.notes" /></Field>
                            </FormLayout>
                            <DetailList v-else :items="[
                                { label: 'Name', value: plan.name },
                                { label: 'In force', value: inForce(plan.effective_from, plan.effective_to) },
                                { label: 'Source', value: sourceWords[plan.source] },
                                { label: 'Values', value: plan.verify ? 'Placeholders to verify' : 'Confirmed' },
                                { label: 'Notes', value: plan.notes },
                            ]" />
                        </section>
                    </TabsContent>

                    <TabsContent value="tables" class="grid max-w-[1100px] gap-8 outline-none">
                        <RateTableGrid v-for="table in tables" :key="table.code" :plan-id="plan.id" :table="table" :currency="plan.currency" :editable="can.edit" />
                        <p v-if="tables.length === 0" class="text-ui text-ink-2">This plan has no rate tables.</p>
                        <div v-if="can.edit"><Button variant="secondary" @click="tableForm.reset(); drawer = 'table'">Add table</Button></div>
                    </TabsContent>

                    <TabsContent value="steps" class="max-w-[1300px] outline-none">
                        <div class="overflow-x-auto border border-line">
                            <table class="w-full border-separate border-spacing-0 text-dense">
                                <thead class="bg-surface-2 text-ink-2">
                                    <tr class="h-(--row-h)">
                                        <th class="w-14 border-b border-line px-3 text-right font-medium">Order</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Step</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Kind</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Amount</th>
                                        <th class="border-b border-line px-3 text-left font-medium">When</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Coverage</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Label</th>
                                        <th v-if="can.edit" class="border-b border-line px-3"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="step in steps" :key="step.code" class="align-top">
                                        <td class="border-b border-line px-3 py-2 text-right tabular-nums">{{ step.order_no }}</td>
                                        <td class="border-b border-line px-3 py-2">{{ step.code }}</td>
                                        <td class="border-b border-line px-3 py-2">{{ words(step.kind) }}</td>
                                        <td class="border-b border-line px-3 py-2 break-all">{{ step.expression }}</td>
                                        <td class="border-b border-line px-3 py-2 break-all text-ink-2">{{ step.condition ?? 'Always' }}</td>
                                        <td class="border-b border-line px-3 py-2">{{ step.applies_to ?? '—' }}</td>
                                        <td class="border-b border-line px-3 py-2">{{ step.label_en }}<span class="block text-ink-2">{{ step.label_bn }}</span></td>
                                        <td v-if="can.edit" class="border-b border-line px-3 py-1 text-right whitespace-nowrap">
                                            <Button variant="ghost" size="sm" @click="openStep(step)">Edit</Button>
                                            <Button variant="ghost" size="sm" @click="removeStep(step)">Remove</Button>
                                        </td>
                                    </tr>
                                    <tr v-if="steps.length === 0"><td :colspan="can.edit ? 8 : 7" class="px-3 py-3 text-ui text-ink-2">No steps. A plan needs at least a base step.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="can.edit" class="mt-3"><Button variant="secondary" @click="openStep(null)">Add step</Button></div>
                    </TabsContent>

                    <TabsContent value="duties" class="max-w-[1100px] outline-none">
                        <p class="mb-3 text-ui text-ink-2">Duties on {{ plan.class_name.toLowerCase() }} premium in force today or starting later. Every rating plan of the class applies them.</p>
                        <div class="overflow-x-auto border border-line">
                            <table class="w-full border-separate border-spacing-0 text-dense">
                                <thead class="bg-surface-2 text-ink-2">
                                    <tr class="h-(--row-h)">
                                        <th class="border-b border-line px-3 text-left font-medium">Duty</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Basis</th>
                                        <th class="border-b border-line px-3 text-right font-medium">Value</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Classes</th>
                                        <th class="border-b border-line px-3 text-left font-medium">In force</th>
                                        <th class="border-b border-line px-3 text-left font-medium">Values</th>
                                        <th v-if="can.record_duties" class="border-b border-line px-3"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="duty in duties" :key="duty.id" class="h-(--row-h) align-top">
                                        <td class="border-b border-line px-3 py-2">{{ duty.label_en }}<span class="block text-ink-2">{{ duty.label_bn }} · {{ duty.code }}</span></td>
                                        <td class="border-b border-line px-3 py-2">{{ basisWords[duty.basis] }}</td>
                                        <td class="border-b border-line px-3 py-2 text-right tabular-nums">{{ dutyValue(duty) }}</td>
                                        <td class="border-b border-line px-3 py-2">{{ duty.class_codes.join(', ') }}</td>
                                        <td class="border-b border-line px-3 py-2 tabular-nums">{{ inForce(duty.effective_from, duty.effective_to) }}<span v-if="!duty.in_force" class="block text-ink-2">Starts later</span></td>
                                        <td class="border-b border-line px-3 py-2"><span v-if="duty.verify" class="text-warn">Verify before use</span><span v-else>Confirmed</span></td>
                                        <td v-if="can.record_duties" class="border-b border-line px-3 py-1 text-right"><Button v-if="!duty.effective_to" variant="ghost" size="sm" @click="endForm.reset(); endingDuty = duty; drawer = 'end-duty'">End</Button></td>
                                    </tr>
                                    <tr v-if="duties.length === 0"><td :colspan="can.record_duties ? 7 : 6" class="px-3 py-3 text-ui text-ink-2">No duties recorded for this class.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="can.record_duties" class="mt-3"><Button variant="secondary" @click="dutyForm.reset(); dutyErrors = {}; drawer = 'duty'">Record duty</Button></div>
                    </TabsContent>

                    <TabsContent value="diff" class="outline-none">
                        <template v-if="others.length">
                            <div class="mb-4 max-w-[360px]">
                                <Field id="compare_with" label="Compare with">
                                    <SelectInput id="compare_with" v-model="compareWith" :options="others.map((v) => ({ value: v.id, label: `v${v.version} · ${statusWord(v.status)} · ${inForce(v.effective_from, v.effective_to)}` }))" />
                                </Field>
                            </div>
                            <PlanDiff v-if="diff && compared" :diff="diff" :currency="plan.currency" :from-label="`v${compared.version}`" :to-label="`v${plan.version}`" />
                        </template>
                        <p v-else class="text-ui text-ink-2">{{ plan.code }} has one version, so there is nothing to compare. Create a new version to change its rates.</p>
                    </TabsContent>

                    <TabsContent value="timeline" class="outline-none">
                        <Deferred data="timeline"><template #fallback><SkeletonRows /></template><Timeline :entries="timeline ?? []" /></Deferred>
                    </TabsContent>
                    <TabsContent value="audit" class="outline-none">
                        <Deferred data="audit"><template #fallback><SkeletonRows /></template><AuditList :rows="audit ?? []" /></Deferred>
                    </TabsContent>
                </div>
            </TabsRoot>
        </div>

        <Drawer :open="drawer === 'version'" :title="`New version of ${plan.code}`" @update:open="(open) => !open && done()">
            <FormLayout submit-label="Create draft version" :dirty="versionForm.isDirty" :processing="versionForm.processing" :error="(versionForm.errors as Record<string, string>).form"
                @submit="versionForm.transform((d) => ({ effective_from: d.effective_from || null, effective_to: d.effective_to || null })).post(`${base}/versions`, { onSuccess: done })" @cancel="done">
                <p class="text-ui text-ink-2">Copies v{{ plan.version }} — its tables, rows and steps — into a draft with the next version number.</p>
                <Field id="version_from" label="In force from" optional hint="Empty keeps this version's dates." :error="versionForm.errors.effective_from"><DateInput v-model="versionForm.effective_from" /></Field>
                <Field id="version_to" label="Until" optional :error="versionForm.errors.effective_to"><DateInput v-model="versionForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'table'" title="Add a rate table" @update:open="(open) => !open && done()">
            <FormLayout submit-label="Add table" :dirty="tableForm.isDirty" :processing="tableForm.processing" :error="(tableForm.errors as Record<string, string>).form" @submit="addTable" @cancel="done">
                <Field id="table_code" label="Code" hint="Steps look the table up by this code, e.g. lookup('motor_base', risk.vehicle_type)." :error="tableForm.errors.code"><TextInput v-model="tableForm.code" placeholder="motor_base" /></Field>
                <Field id="table_name" label="Name" :error="tableForm.errors.name"><TextInput v-model="tableForm.name" /></Field>
                <Field id="table_type" label="Values" :error="tableForm.errors.value_type"><SelectInput id="table_type" v-model="tableForm.value_type" :options="value_types.map((t) => ({ value: t, label: typeWords[t] ?? t }))" /></Field>
                <Field v-if="tableForm.value_type !== 'band'" id="table_dimensions" label="Dimensions" hint="In lookup order, separated by commas: vehicle_type, cc_band." :error="tableForm.errors.dimensions ?? (tableForm.errors as Record<string, string>)['dimensions.0']">
                    <TextInput v-model="tableForm.dimensions" />
                </Field>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'step'" :title="editingStep ? `Edit step ${editingStep}` : 'Add a step'" width="w-[560px]" @update:open="(open) => !open && done()">
            <FormLayout :submit-label="editingStep ? 'Save step' : 'Add step'" :dirty="stepForm.isDirty" :processing="stepForm.processing" :error="(stepForm.errors as Record<string, string>).form" @submit="saveStep" @cancel="done">
                <div class="grid grid-cols-[96px_1fr_1fr] gap-3">
                    <Field id="step_order" label="Order" :error="stepForm.errors.order_no"><TextInput v-model="stepForm.order_no" inputmode="numeric" /></Field>
                    <Field id="step_code" label="Code" :error="stepForm.errors.code"><TextInput v-model="stepForm.code" placeholder="young_driver" /></Field>
                    <Field id="step_kind" label="Kind" :error="stepForm.errors.kind"><SelectInput id="step_kind" v-model="stepForm.kind" :options="step_kinds.map((k) => ({ value: k, label: words(k) }))" /></Field>
                </div>
                <Field id="step_expression" label="Amount" hint="In minor units, e.g. per_mille(sum_insured, lookup('motor_base', risk.vehicle_type)) or pct(running.premium, 1000)." :error="stepForm.errors.expression">
                    <template #default="{ id, describedBy, invalid }">
                        <textarea :id="id" v-model="stepForm.expression" rows="3" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full rounded-control border border-line-control bg-surface px-2 py-1.5 text-body aria-[invalid=true]:border-danger" />
                    </template>
                </Field>
                <Field id="step_condition" label="Runs when" optional hint="True or false, e.g. risk.driver_age < 25. Empty runs always." :error="stepForm.errors.condition"><TextInput v-model="stepForm.condition" /></Field>
                <Field id="step_coverage" label="Coverage" optional hint="The coverage code this step rates; a coverage step needs one." :error="stepForm.errors.applies_to"><TextInput v-model="stepForm.applies_to" /></Field>
                <Field id="step_label_en" label="Label (English)" :error="stepForm.errors.label_en"><TextInput v-model="stepForm.label_en" /></Field>
                <Field id="step_label_bn" label="Label (Bangla)" :error="stepForm.errors.label_bn"><TextInput v-model="stepForm.label_bn" /></Field>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'duty'" title="Record a duty" width="w-[520px]" @update:open="(open) => !open && done()">
            <FormLayout submit-label="Record duty" :dirty="dutyForm.isDirty" :processing="dutyForm.processing" :error="(dutyForm.errors as Record<string, string>).form" @submit="recordDuty" @cancel="done">
                <p class="text-ui text-ink-2">A duty value is never edited: end the one in force, then record its successor from the same day.</p>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="duty_code" label="Duty" :error="dutyForm.errors.code"><SelectInput id="duty_code" v-model="dutyForm.code" :options="[{ value: 'vat', label: 'VAT' }, { value: 'stamp', label: 'Stamp duty' }, { value: 'levy', label: 'Levy' }]" /></Field>
                    <Field id="duty_basis" label="Basis" :error="dutyForm.errors.basis"><SelectInput id="duty_basis" v-model="dutyForm.basis" :options="Object.entries(basisWords).map(([value, label]) => ({ value, label }))" /></Field>
                </div>
                <Field v-if="dutyForm.basis === 'pct_of_premium'" id="duty_rate" label="Rate (%)" :error="dutyErrors.rate ?? (dutyForm.errors as Record<string, string>).rate_bp"><TextInput v-model="dutyForm.rate" inputmode="decimal" placeholder="15" /></Field>
                <Field v-else-if="dutyForm.basis === 'flat_per_policy'" id="duty_amount" :label="`Amount (${plan.currency})`" :error="dutyErrors.amount ?? (dutyForm.errors as Record<string, string>).amount_minor"><TextInput v-model="dutyForm.amount" inputmode="decimal" placeholder="50.00" /></Field>
                <fieldset v-else>
                    <legend class="mb-1 text-ui font-medium">Bands by sum insured ({{ plan.currency }})</legend>
                    <div v-for="(band, i) in dutyForm.bands" :key="i" class="mb-2 grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
                        <input v-model="band.from" inputmode="decimal" class="h-8 rounded-control border border-line-control bg-surface px-2 text-right text-body tabular-nums" :aria-label="`Band ${i + 1} from`" placeholder="From" />
                        <input v-model="band.to" inputmode="decimal" class="h-8 rounded-control border border-line-control bg-surface px-2 text-right text-body tabular-nums" :aria-label="`Band ${i + 1} below`" placeholder="Below (empty: no end)" />
                        <input v-model="band.amount" inputmode="decimal" class="h-8 rounded-control border border-line-control bg-surface px-2 text-right text-body tabular-nums" :aria-label="`Band ${i + 1} duty`" placeholder="Duty" />
                        <Button variant="ghost" size="sm" @click="dutyForm.bands.splice(i, 1)">Remove</Button>
                    </div>
                    <Button variant="ghost" size="sm" @click="dutyForm.bands.push({ from: dutyForm.bands.at(-1)?.to ?? '', to: '', amount: '' })">Add band</Button>
                    <p v-if="dutyErrors.bands" class="text-dense text-danger" role="alert">{{ dutyErrors.bands }}</p>
                </fieldset>
                <fieldset>
                    <legend class="mb-1 text-ui font-medium">Product classes</legend>
                    <div class="flex flex-wrap gap-x-5 gap-y-1">
                        <label v-for="c in classes" :key="c.code" class="flex items-center gap-2 text-ui"><input v-model="dutyForm.class_codes" type="checkbox" :value="c.code" class="size-3.5 accent-accent" />{{ c.name }}</label>
                    </div>
                    <p v-if="dutyForm.errors.class_codes" class="text-dense text-danger" role="alert">{{ dutyForm.errors.class_codes }}</p>
                </fieldset>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="duty_from" label="In force from" :error="dutyForm.errors.effective_from"><DateInput v-model="dutyForm.effective_from" /></Field>
                    <Field id="duty_to" label="Until" optional :error="dutyForm.errors.effective_to"><DateInput v-model="dutyForm.effective_to" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="duty_label_en" label="Label (English)" :error="dutyForm.errors.label_en"><TextInput v-model="dutyForm.label_en" placeholder="VAT" /></Field>
                    <Field id="duty_label_bn" label="Label (Bangla)" :error="dutyForm.errors.label_bn"><TextInput v-model="dutyForm.label_bn" placeholder="মূসক" /></Field>
                </div>
                <label class="flex items-center gap-2 text-ui"><input v-model="dutyForm.verify" type="checkbox" class="size-3.5 accent-accent" />Placeholder value to verify against current NBR/IDRA rules</label>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'end-duty' && endingDuty !== null" :title="`End ${endingDuty?.label_en ?? 'duty'}`" @update:open="(open) => !open && done()">
            <FormLayout submit-label="End duty" :dirty="endForm.isDirty" :processing="endForm.processing" :error="(endForm.errors as Record<string, string>).form"
                @submit="endForm.post(`/rating/duties/${endingDuty?.id}/end`, { preserveScroll: true, onSuccess: done })" @cancel="done">
                <Field id="duty_end" label="No longer applies from" hint="Quotes dated before this day keep the duty; record its successor from the same day." :error="endForm.errors.effective_to"><DateInput v-model="endForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
