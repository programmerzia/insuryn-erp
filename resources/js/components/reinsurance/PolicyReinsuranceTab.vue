<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import Drawer from '@/components/ui/Drawer.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';
import type { PolicyReinsurance } from '@/lib/reinsurance';

/** The policy page's Reinsurance tab (market gap G4): how the risk is shared — SBC, treaty, retained, above capacity — each reinsurer's share, and facultative placements. */
const props = defineProps<{ policyId: string; title: string; currency: string; data: PolicyReinsurance }>();

const open = ref(false);
const fac = useMoneyForm(() => `/policies/${props.policyId}/facultative`, {
    reinsurer_id: props.data.reinsurers[0]?.value ?? '', share_percent: '', ceded_sum_insured: props.data.position && props.data.position.above_capacity_minor > 0 ? formatMoney(props.data.position.above_capacity) : '',
    premium: '', commission_percent: '20.00', slip_reference: '', placed_on: props.data.today,
}, () => (open.value = false));
const errors = computed(() => fac.form.errors as Record<string, string>);
const th = 'border-b border-line px-3 text-left font-medium';
const thr = 'border-b border-line px-3 text-right font-medium';
</script>

<template>
    <div class="grid max-w-[1100px] gap-6">
        <div v-if="data.position?.above_capacity_minor" class="border-l-2 border-warn bg-surface-2 px-3 py-2 text-ui" role="status">
            {{ formatMoney(data.position.above_capacity) }} {{ currency }} of this risk is above the treaty's capacity and not yet placed. Place it facultatively with a reinsurer.
        </div>
        <section class="flex flex-wrap items-start gap-8">
            <div class="min-w-[320px] flex-1">
                <h2 class="mb-2 text-ui font-medium">How the risk is shared</h2>
                <p v-if="!data.position" class="text-ui text-ink-2">Not ceded: the policy was issued before any treaty, or is not issued yet.</p>
                <DetailList v-else :items="[
                    { label: 'Treaty', value: data.position.treaty ? `${data.position.treaty} (${data.position.treaty_type})` : 'None in force' },
                    { label: `Sum insured (${currency})`, value: formatMoney(data.position.sum_insured), num: true },
                    { label: 'Net premium', value: formatMoney(data.position.net_premium), num: true },
                    { label: 'SBC compulsory share', value: formatMoney(data.position.sbc), num: true },
                    { label: 'Ceded to the treaty', value: formatMoney(data.position.treaty_ceded), num: true },
                    { label: 'Retained', value: formatMoney(data.position.retained), num: true },
                    { label: 'Above capacity, unplaced', value: formatMoney(data.position.above_capacity), num: true },
                    { label: 'Note', value: data.position.note },
                ]" />
            </div>
            <button v-if="data.canPlace" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="open = true">Place facultative</button>
        </section>

        <section>
            <h2 class="mb-2 text-ui font-medium">Reinsurers' shares</h2>
            <div class="overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="th">Reinsurer</th><th :class="th">Basis</th><th :class="thr">Share %</th><th :class="thr">Ceded sum insured ({{ currency }})</th><th :class="thr">Ceded premium</th><th :class="thr">Commission</th></tr></thead>
                    <tbody>
                        <tr v-for="s in data.shares" :key="`${s.basis}-${s.reinsurer}`" class="h-(--row-h)">
                            <td class="border-b border-line px-3">{{ s.reinsurer }}</td><td class="border-b border-line px-3">{{ s.basis }}</td><td class="num border-b border-line px-3">{{ s.share }}</td>
                            <td class="num border-b border-line px-3">{{ formatMoney(s.ceded_sum_insured) }}</td><td class="num border-b border-line px-3">{{ formatMoney(s.premium) }}</td><td class="num border-b border-line px-3">{{ formatMoney(s.commission) }}</td>
                        </tr>
                        <tr v-if="data.shares.length === 0"><td colspan="6" class="px-3 py-6 text-center text-ui text-ink-2">Nothing ceded: the whole risk is retained.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="data.placements.length">
            <h2 class="mb-2 text-ui font-medium">Facultative placements</h2>
            <div class="overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="th">Placed</th><th :class="th">Reinsurer</th><th :class="th">Slip</th><th :class="thr">Share %</th><th :class="thr">Ceded sum insured</th><th :class="thr">Premium</th><th :class="thr">Commission</th></tr></thead>
                    <tbody><tr v-for="f in data.placements" :key="f.id" class="h-(--row-h)">
                        <td class="border-b border-line px-3">{{ formatDate(f.placed_on) }}</td><td class="border-b border-line px-3">{{ f.reinsurer }}</td><td class="border-b border-line px-3">{{ f.slip_reference ?? '—' }}</td>
                        <td class="num border-b border-line px-3">{{ f.share }}</td><td class="num border-b border-line px-3">{{ formatMoney(f.ceded_sum_insured) }}</td><td class="num border-b border-line px-3">{{ formatMoney(f.premium) }}</td><td class="num border-b border-line px-3">{{ formatMoney(f.commission) }}</td>
                    </tr></tbody>
                </table>
            </div>
        </section>

        <section v-if="data.claims.length">
            <h2 class="mb-2 text-ui font-medium">Reinsurers' share of claims</h2>
            <div class="max-w-[760px] overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="th">Claim</th><th :class="th">Reinsurer</th><th :class="thr">Share of outstanding ({{ currency }})</th><th :class="thr">Recoverable</th></tr></thead>
                    <tbody><tr v-for="c in data.claims" :key="`${c.claim_id}-${c.reinsurer}`" class="h-(--row-h)">
                        <td class="border-b border-line px-3"><Link :href="`/claims/${c.claim_id}`" class="text-accent-text hover:underline">{{ c.claim_number }}</Link></td><td class="border-b border-line px-3">{{ c.reinsurer }}</td>
                        <td class="num border-b border-line px-3">{{ formatMoney(c.outstanding) }}</td><td class="num border-b border-line px-3">{{ formatMoney(c.recoverable) }}</td>
                    </tr></tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-ui font-medium">Cession movements</h2>
            <div class="overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="th">Date</th><th :class="th">Movement</th><th :class="th">Basis</th><th :class="th">Reinsurer</th><th :class="thr">Ceded sum insured</th><th :class="thr">Premium</th><th :class="thr">Commission</th></tr></thead>
                    <tbody>
                        <tr v-for="m in data.movements" :key="m.id" class="h-(--row-h)">
                            <td class="border-b border-line px-3">{{ formatDate(m.date) }}</td><td class="border-b border-line px-3">{{ m.movement }}</td><td class="border-b border-line px-3">{{ m.basis }}</td><td class="border-b border-line px-3">{{ m.reinsurer }}</td>
                            <td class="num border-b border-line px-3">{{ formatMoney(m.ceded_sum_insured) }}</td><td class="num border-b border-line px-3">{{ formatMoney(m.premium) }}</td><td class="num border-b border-line px-3">{{ formatMoney(m.commission) }}</td>
                        </tr>
                        <tr v-if="data.movements.length === 0"><td colspan="7" class="px-3 py-6 text-center text-ui text-ink-2">No cession movements.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Drawer v-model:open="open" :title="`Place facultative on ${title}`">
            <p class="mb-4 text-ui text-ink-2">The share, premium and commission agreed on the reinsurer's slip. Placing it posts the ceded premium and commission.</p>
            <FormLayout submit-label="Review and place" :dirty="fac.form.isDirty" :processing="fac.form.processing" :error="errors.form" @submit="fac.review" @cancel="open = false">
                <Field id="fac_reinsurer" label="Reinsurer" :error="errors.reinsurer_id"><SelectInput id="fac_reinsurer" v-model="fac.form.reinsurer_id" :options="data.reinsurers" /></Field>
                <Field id="fac_share" label="Share of the risk %" :error="errors.share_percent"><TextInput id="fac_share" v-model="fac.form.share_percent" inputmode="decimal" placeholder="25.00" /></Field>
                <Field id="fac_si" :label="`Ceded sum insured (${currency})`" hint="Leave empty to cede the share of the whole sum insured." optional :error="errors.ceded_sum_insured"><MoneyInput id="fac_si" v-model="fac.form.ceded_sum_insured" /></Field>
                <Field id="fac_premium" :label="`Facultative premium (${currency})`" :error="errors.premium"><MoneyInput id="fac_premium" v-model="fac.form.premium" /></Field>
                <Field id="fac_commission" label="Commission %" :error="errors.commission_percent"><TextInput id="fac_commission" v-model="fac.form.commission_percent" inputmode="decimal" /></Field>
                <Field id="fac_slip" label="Slip reference" optional :error="errors.slip_reference"><TextInput id="fac_slip" v-model="fac.form.slip_reference" :maxlength="64" /></Field>
                <Field id="fac_on" label="Placed on" :error="errors.placed_on"><DateInput id="fac_on" v-model="fac.form.placed_on" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="fac.previewOpen.value" :result="fac.preview.value" :title="`Place facultative on ${title}?`" confirm-label="Place and post" :currency="currency" :processing="fac.form.processing" @confirm="fac.post" />
    </div>
</template>
