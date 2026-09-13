<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { producerTypeCodes, producerTypeLabel } from '@/lib/distribution';
import { formatDate } from '@/lib/format';

/**
 * Distribution design note §6 scheme and rule editor. Rules are checked against the compliance profile when added (mode, producer types,
 * levels, caps on direct + overrides); the server's refusal is shown in place. Rules are ended, never edited.
 */
interface Cap { product_id: string | null; policy_year_from: number; policy_year_to: number; max_total_percent: string }
interface Rule { id: string; product: string; producer_type: string | null; level_code: string | null; basis: string; years: string; rate_percent: string | null; override_rate_percent: string | null;
    cap_percent: string | null; min_persistency_percent: string | null; renewal_requires_valid_licence: boolean; pays_after_termination: boolean; effective_from: string; effective_to: string | null }
const props = defineProps<{
    scheme: { id: string; code: string; name: string; mode: string; effective_from: string; effective_to: string | null; withholding: string | null };
    profile: { allowed_producer_types: string[] | null; non_life_commission_allowed: boolean; caps: Cap[] };
    levels: { code: string; rank: number; label: string }[];
    rules: Rule[];
    products: { id: string; code: string; name: string; insurance_class: string }[];
    can: { manage: boolean };
}>();

const base = `/distribution/schemes/${props.scheme.id}`;
const types = producerTypeCodes;
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const modeWords: Record<string, string> = { commission: 'Commission', salary_incentive: 'Salary and incentives', hybrid: 'Hybrid', none: 'No pay' };
const paysCommission = ['commission', 'hybrid'].includes(props.scheme.mode);

const profile = useForm({
    allowed_producer_types: props.profile.allowed_producer_types ?? [],
    non_life_commission_allowed: props.profile.non_life_commission_allowed,
    caps: props.profile.caps.map((c) => ({ ...c, product_id: c.product_id ?? '' })),
});
const levels = useForm({ levels: props.levels.map((l) => ({ ...l })) });
const drawer = ref(false);
const rule = useForm({ product_id: '', producer_type: '', level_code: '', basis: 'premium_received', policy_year_from: 1, policy_year_to: 1, rate_percent: '', override_rate_percent: '',
    cap_percent: '', min_persistency_percent: '', renewal_requires_valid_licence: true, pays_after_termination: false, effective_from: props.scheme.effective_from });
const ending = ref<Rule | null>(null);
const endForm = useForm({ effective_to: '' });

const columns: DataColumn<Rule>[] = [
    { id: 'product', header: 'Product', value: (r) => r.product, width: 130 },
    { id: 'type', header: 'Producer', value: (r) => producerTypeLabel(r.producer_type), width: 100 },
    { id: 'level', header: 'Level', value: (r) => r.level_code ?? 'Any', width: 70 },
    { id: 'basis', header: 'On', value: (r) => words(r.basis), width: 140 },
    { id: 'years', header: 'Policy years', value: (r) => r.years, width: 100 },
    { id: 'rate', header: 'Direct (%)', type: 'number', value: (r) => (r.rate_percent === '0.00' ? null : r.rate_percent), width: 90 },
    { id: 'override', header: 'Override (%)', type: 'number', value: (r) => (r.override_rate_percent === '0.00' ? null : r.override_rate_percent), width: 100 },
    { id: 'cap', header: 'Rule cap (%)', type: 'number', value: (r) => r.cap_percent, width: 100 },
    { id: 'persistency', header: 'Min persistency (%)', type: 'number', value: (r) => r.min_persistency_percent, width: 130 },
    { id: 'licence', header: 'Renewal needs licence', value: (r) => (r.renewal_requires_valid_licence ? 'Yes' : 'No'), width: 150, muted: true },
    { id: 'from', header: 'From', type: 'date', value: (r) => r.effective_from },
    { id: 'to', header: 'Until', type: 'date', value: (r) => r.effective_to },
];

function addLevel(): void {
    levels.levels.push({ code: '', rank: (levels.levels.at(-1)?.rank ?? 0) + 1, label: '' });
}
</script>

<template>
    <AppLayout :title="scheme.code">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <Link href="/distribution/schemes" class="text-dense text-accent-text hover:underline">Compensation schemes</Link>
                <h1 class="text-title font-semibold">{{ scheme.code }} · {{ scheme.name }}</h1>
                <p class="text-ui text-ink-2">
                    {{ modeWords[scheme.mode] }} · in force {{ formatDate(scheme.effective_from) }} {{ scheme.effective_to ? `to ${formatDate(scheme.effective_to)}` : 'onwards' }} · withholding {{ scheme.withholding ?? 'none' }}
                </p>
            </div>
            <Button v-if="can.manage && paysCommission" @click="drawer = true">Add rule</Button>
        </div>
        <FormBanner />

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <form class="max-w-[640px]" aria-labelledby="profile-heading" @submit.prevent="profile.put(`${base}/compliance-profile`, { preserveScroll: true })">
                <h2 id="profile-heading" class="mb-3 text-section font-semibold">Compliance profile</h2>
                <fieldset class="mb-4">
                    <legend class="mb-1 text-ui font-medium">Producers who may earn commission</legend>
                    <p class="mb-2 text-dense text-ink-2">None ticked: every type.</p>
                    <div class="flex flex-wrap gap-x-5 gap-y-1">
                        <label v-for="t in types" :key="t" class="flex items-center gap-2 text-ui"><input v-model="profile.allowed_producer_types" type="checkbox" :value="t" :disabled="!can.manage" class="size-3.5 accent-accent" />{{ producerTypeLabel(t) }}</label>
                    </div>
                </fieldset>
                <label class="mb-4 flex items-start gap-2 text-ui">
                    <input v-model="profile.non_life_commission_allowed" type="checkbox" :disabled="!can.manage" class="mt-1 size-3.5 accent-accent" />
                    <span>Commission on non-life products is allowed<span class="block text-dense text-ink-2">Off until the regulator's current rule is confirmed for this scheme.</span></span>
                </label>
                <fieldset>
                    <legend class="mb-2 text-ui font-medium">Caps on total commission (direct and overrides)</legend>
                    <div class="overflow-x-auto border border-line">
                        <table class="w-full border-separate border-spacing-0 text-dense">
                            <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-2 text-left font-medium">Product</th><th class="border-b border-line px-2 text-left font-medium">Years from</th><th class="border-b border-line px-2 text-left font-medium">to</th><th class="border-b border-line px-2 text-right font-medium">Max (%)</th><th class="border-b border-line px-2"><span class="sr-only">Remove</span></th></tr></thead>
                            <tbody>
                                <tr v-for="(cap, i) in profile.caps" :key="i">
                                    <td class="border-b border-line px-2 py-1"><SelectInput :id="`cap_product_${i}`" v-model="cap.product_id" :aria-label="`Cap ${i + 1} product`" placeholder="Every product" :options="products.map((p) => ({ value: p.id, label: p.code }))" /></td>
                                    <td class="w-20 border-b border-line px-2 py-1"><input v-model.number="cap.policy_year_from" type="number" min="1" max="99" class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-right tabular-nums" :aria-label="`Cap ${i + 1} from year`" /></td>
                                    <td class="w-20 border-b border-line px-2 py-1"><input v-model.number="cap.policy_year_to" type="number" min="1" max="99" class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-right tabular-nums" :aria-label="`Cap ${i + 1} to year`" /></td>
                                    <td class="w-24 border-b border-line px-2 py-1"><input v-model="cap.max_total_percent" inputmode="decimal" class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-right tabular-nums" :aria-label="`Cap ${i + 1} maximum percent`" /></td>
                                    <td class="w-16 border-b border-line px-2 py-1 text-right"><Button v-if="can.manage" variant="ghost" size="sm" @click="profile.caps.splice(i, 1)">Remove</Button></td>
                                </tr>
                                <tr v-if="profile.caps.length === 0"><td colspan="5" class="px-2 py-3 text-ui text-ink-2">No caps.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-for="(message, key) in profile.errors" :key="key" class="mt-1 text-dense text-danger" role="alert">{{ message }}</p>
                    <div v-if="can.manage" class="mt-3 flex gap-2">
                        <Button variant="ghost" size="sm" @click="profile.caps.push({ product_id: '', policy_year_from: 1, policy_year_to: 1, max_total_percent: '' })">Add cap</Button>
                        <Button type="submit" variant="secondary" :disabled="profile.processing || !profile.isDirty">Save profile</Button>
                    </div>
                </fieldset>
            </form>

            <form class="max-w-[640px]" aria-labelledby="levels-heading" @submit.prevent="levels.put(`${base}/levels`, { preserveScroll: true })">
                <h2 id="levels-heading" class="mb-3 text-section font-semibold">Hierarchy levels</h2>
                <p class="mb-2 text-dense text-ink-2">Rank 1 is the lowest; a manager must rank above the producers reporting to it.</p>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-2 text-left font-medium">Rank</th><th class="border-b border-line px-2 text-left font-medium">Code</th><th class="border-b border-line px-2 text-left font-medium">Name</th><th class="border-b border-line px-2"><span class="sr-only">Remove</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(level, i) in levels.levels" :key="i">
                                <td class="w-20 border-b border-line px-2 py-1"><input v-model.number="level.rank" type="number" min="1" class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-right tabular-nums" :aria-label="`Level ${i + 1} rank`" /></td>
                                <td class="w-28 border-b border-line px-2 py-1"><input v-model="level.code" class="h-8 w-full rounded-control border border-line-control bg-surface px-2" :aria-label="`Level ${i + 1} code`" /></td>
                                <td class="border-b border-line px-2 py-1"><input v-model="level.label" class="h-8 w-full rounded-control border border-line-control bg-surface px-2" :aria-label="`Level ${i + 1} name`" /></td>
                                <td class="w-16 border-b border-line px-2 py-1 text-right"><Button v-if="can.manage" variant="ghost" size="sm" @click="levels.levels.splice(i, 1)">Remove</Button></td>
                            </tr>
                            <tr v-if="levels.levels.length === 0"><td colspan="4" class="px-2 py-3 text-ui text-ink-2">No levels. Add them for a multi-level agency.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p v-for="(message, key) in levels.errors" :key="key" class="mt-1 text-dense text-danger" role="alert">{{ message }}</p>
                <div v-if="can.manage" class="mt-3 flex gap-2">
                    <Button variant="ghost" size="sm" @click="addLevel">Add level</Button>
                    <Button type="submit" variant="secondary" :disabled="levels.processing || !levels.isDirty">Save levels</Button>
                </div>
            </form>
        </div>

        <section class="mt-8" aria-labelledby="rules-heading">
            <h2 id="rules-heading" class="mb-2 text-section font-semibold">Rules</h2>
            <p v-if="!paysCommission" class="text-ui text-ink-2">This scheme pays no commission; its producers are paid through salary and incentive plans.</p>
            <div v-else class="h-[360px] border border-line">
                <DataTable :id="`scheme-rules-${scheme.id}`" label="Compensation rules" :columns="columns" :rows="rules" :row-key="(r) => r.id" :url-sync="false" :open-on-click="false"
                    empty-text="No rules yet. Add the first rate." compact-toolbar @open="(r) => can.manage && !r.effective_to && (ending = r)" />
            </div>
            <p v-if="paysCommission && can.manage" class="mt-2 text-dense text-ink-2">Select a rule and press Enter to end it from a date.</p>
        </section>

        <Drawer v-model:open="drawer" title="Add a rule" width="w-[520px]">
            <FormLayout submit-label="Add rule" :dirty="rule.isDirty" :processing="rule.processing" :error="(rule.errors as Record<string, string>).form" @submit="rule.post(`${base}/rules`, { preserveScroll: true, onSuccess: () => { rule.reset(); drawer = false; } })" @cancel="drawer = false">
                <Field id="rule_product" label="Product" optional :error="rule.errors.product_id"><SelectInput id="rule_product" v-model="rule.product_id" placeholder="Every product" :options="products.map((p) => ({ value: p.id, label: `${p.code} · ${p.name} (${p.insurance_class === 'life' ? 'life' : 'non-life'})` }))" /></Field>
                <Field id="rule_type" label="Producer type" optional :error="rule.errors.producer_type"><SelectInput id="rule_type" v-model="rule.producer_type" placeholder="Any type" :options="types.map((t) => ({ value: t, label: producerTypeLabel(t) }))" /></Field>
                <Field id="rule_level" label="Level" optional hint="An override is paid to managers at this level." :error="rule.errors.level_code"><SelectInput id="rule_level" v-model="rule.level_code" placeholder="Any level" :options="props.levels.map((l) => ({ value: l.code, label: l.label }))" /></Field>
                <Field id="rule_basis" label="Commission on" :error="rule.errors.basis"><SelectInput id="rule_basis" v-model="rule.basis" :options="[{ value: 'premium_received', label: 'Premium received' }, { value: 'premium_written', label: 'Premium written' }, { value: 'net_premium', label: 'Net premium' }]" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="rule_year_from" label="From policy year" :error="rule.errors.policy_year_from"><TextInput v-model="rule.policy_year_from" inputmode="numeric" /></Field>
                    <Field id="rule_year_to" label="To policy year" hint="1 to 1 is the first year." :error="rule.errors.policy_year_to"><TextInput v-model="rule.policy_year_to" inputmode="numeric" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="rule_rate" label="Direct rate (%)" optional :error="rule.errors.rate_percent"><TextInput v-model="rule.rate_percent" inputmode="decimal" placeholder="25.00" /></Field>
                    <Field id="rule_override" label="Override rate (%)" optional :error="rule.errors.override_rate_percent"><TextInput v-model="rule.override_rate_percent" inputmode="decimal" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="rule_cap" label="Rule cap (%)" optional :error="rule.errors.cap_percent"><TextInput v-model="rule.cap_percent" inputmode="decimal" /></Field>
                    <Field id="rule_persistency" label="Minimum persistency (%)" optional hint="Held until met at the statement run." :error="rule.errors.min_persistency_percent"><TextInput v-model="rule.min_persistency_percent" inputmode="decimal" /></Field>
                </div>
                <label class="flex items-center gap-2 text-ui"><input v-model="rule.renewal_requires_valid_licence" type="checkbox" class="size-3.5 accent-accent" />Renewal commission needs a valid licence</label>
                <label class="flex items-center gap-2 text-ui"><input v-model="rule.pays_after_termination" type="checkbox" class="size-3.5 accent-accent" />Renewal commission continues after termination</label>
                <Field id="rule_from" label="In force from" :error="rule.errors.effective_from"><DateInput v-model="rule.effective_from" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="ending !== null" :title="ending ? `End the ${ending.product} rule` : 'End rule'" @update:open="(o) => !o && (ending = null)">
            <FormLayout submit-label="End rule" :dirty="endForm.isDirty" :processing="endForm.processing" :error="(endForm.errors as Record<string, string>).form" @submit="endForm.post(`/distribution/rules/${ending?.id}/end`, { preserveScroll: true, onSuccess: () => (ending = null) })" @cancel="ending = null">
                <Field id="rule_end" label="No longer applies from" hint="Commission before this date keeps the rule; payouts already made stay as they were." :error="endForm.errors.effective_to"><DateInput v-model="endForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
