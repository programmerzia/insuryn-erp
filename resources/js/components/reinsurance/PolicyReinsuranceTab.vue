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
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import { formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';
import type { ClaimShareRow, MovementRow, PlacementRow, PolicyReinsurance, ShareRow } from '@/lib/reinsurance';

/** The policy page's Reinsurance tab (market gap G4): how the risk is shared — SBC, treaty, retained, above capacity — each reinsurer's share, and facultative placements. */
const props = defineProps<{ policyId: string; title: string; currency: string; data: PolicyReinsurance }>();

const open = ref(false);
const fac = useMoneyForm(() => `/policies/${props.policyId}/facultative`, {
    reinsurer_id: props.data.reinsurers[0]?.value ?? '', share_percent: '', ceded_sum_insured: props.data.position && props.data.position.above_capacity_minor > 0 ? formatMoney(props.data.position.above_capacity) : '',
    premium: '', commission_percent: '20.00', slip_reference: '', placed_on: props.data.today,
}, () => (open.value = false));
const errors = computed(() => fac.form.errors as Record<string, string>);

const shareColumns: DataColumn<ShareRow>[] = [
    { id: 'reinsurer', header: 'Reinsurer', value: (s) => s.reinsurer, width: 240 },
    { id: 'basis', header: 'Basis', value: (s) => s.basis, width: 130 },
    { id: 'share', header: 'Share %', type: 'number', value: (s) => s.share, width: 90 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (s) => s.ceded_sum_insured, width: 170 },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (s) => s.premium, total: true, width: 170 },
    { id: 'commission', header: 'Commission', type: 'money', value: (s) => s.commission, total: true, width: 170 },
];
const placementColumns: DataColumn<PlacementRow>[] = [
    { id: 'placed_on', header: 'Placed on', type: 'date', value: (f) => f.placed_on, width: 110 },
    { id: 'reinsurer', header: 'Reinsurer', value: (f) => f.reinsurer, width: 120 },
    { id: 'slip', header: 'Slip reference', value: (f) => f.slip_reference ?? '—', width: 140 },
    { id: 'share', header: 'Share %', type: 'number', value: (f) => f.share, width: 90 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (f) => f.ceded_sum_insured, width: 170 },
    { id: 'premium', header: 'Facultative premium', type: 'money', value: (f) => f.premium, total: true, width: 170 },
    { id: 'commission', header: 'Commission', type: 'money', value: (f) => f.commission, total: true, width: 170 },
];
const claimColumns: DataColumn<ClaimShareRow>[] = [
    { id: 'claim', header: 'Claim', value: (c) => c.claim_number, href: (c) => `/claims/${c.claim_id}`, width: 160 },
    { id: 'reinsurer', header: 'Reinsurer', value: (c) => c.reinsurer, width: 120 },
    { id: 'outstanding', header: 'Share of outstanding', type: 'money', value: (c) => c.outstanding, total: true, width: 170 },
    { id: 'recoverable', header: 'Recoverable', type: 'money', value: (c) => c.recoverable, total: true, width: 170 },
];
const movementColumns: DataColumn<MovementRow>[] = [
    { id: 'date', header: 'Date', type: 'date', value: (m) => m.date, width: 110 },
    { id: 'movement', header: 'Movement', value: (m) => m.movement, width: 120 },
    { id: 'basis', header: 'Basis', value: (m) => m.basis, width: 130 },
    { id: 'reinsurer', header: 'Reinsurer', value: (m) => m.reinsurer, width: 110 },
    { id: 'si', header: 'Ceded sum insured', type: 'money', value: (m) => m.ceded_sum_insured, width: 170 },
    { id: 'premium', header: 'Ceded premium', type: 'money', value: (m) => m.premium, total: true, width: 170 },
    { id: 'commission', header: 'Commission', type: 'money', value: (m) => m.commission, total: true, width: 170 },
];
</script>

<template>
    <div class="grid max-w-[1100px] gap-6">
        <div v-if="data.position?.above_capacity_minor" class="border-l-2 border-warn bg-surface-2 px-3 py-2 text-ui" role="status">
            {{ formatMoney(data.position.above_capacity) }} {{ currency }} of this risk is above the treaty's capacity and not yet placed. Place it facultatively with a reinsurer.
        </div>
        <section class="flex flex-wrap items-start gap-8">
            <div class="min-w-[min(320px,100%)] flex-1">
                <h2 class="mb-2 text-section font-semibold">How the risk is shared</h2>
                <p v-if="!data.position" class="text-ui text-ink-2">Not ceded: the policy was issued before any treaty, or is not issued yet.</p>
                <template v-else>
                    <DetailList :items="[
                        { label: 'Treaty', value: data.position.treaty ? `${data.position.treaty} (${data.position.treaty_type})` : 'None in force' },
                        { label: `Sum insured (${currency})`, value: formatMoney(data.position.sum_insured), num: true },
                        { label: `Net premium (${currency})`, value: formatMoney(data.position.net_premium), num: true },
                        { label: 'SBC compulsory share', value: formatMoney(data.position.sbc), num: true },
                        { label: 'Ceded to the treaty', value: formatMoney(data.position.treaty_ceded), num: true },
                        { label: 'Retained', value: formatMoney(data.position.retained), num: true },
                        { label: 'Above capacity, unplaced', value: formatMoney(data.position.above_capacity), num: true },
                        { label: 'Note', value: data.position.note },
                    ]" />
                    <Link v-if="data.position.treaty_id" :href="`/reinsurance/treaties/${data.position.treaty_id}`" class="mt-2 inline-block text-ui text-accent-text hover:underline">Open the treaty</Link>
                </template>
            </div>
            <button v-if="data.canPlace" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="open = true">Place facultative</button>
        </section>

        <section>
            <h2 class="mb-2 text-section font-semibold">Reinsurers' shares</h2>
            <DataTable id="policy-ri-shares" label="Reinsurers' shares" :columns="shareColumns" :rows="data.shares" :row-key="(s) => `${s.basis}-${s.reinsurer}`" :currency="currency"
                :url-sync="false" :open-on-click="false" compact-toolbar empty-text="Nothing ceded: the whole risk is retained." />
        </section>

        <section v-if="data.placements.length">
            <h2 class="mb-2 text-section font-semibold">Facultative placements</h2>
            <DataTable id="policy-ri-placements" label="Facultative placements" :columns="placementColumns" :rows="data.placements" :row-key="(f) => f.id" :currency="currency"
                :url-sync="false" :open-on-click="false" compact-toolbar empty-text="No facultative placements." />
        </section>

        <section v-if="data.claims.length">
            <h2 class="mb-2 text-section font-semibold">Reinsurers' share of claims</h2>
            <DataTable id="policy-ri-claims" label="Reinsurers' share of claims" :columns="claimColumns" :rows="data.claims" :row-key="(c) => `${c.claim_id}-${c.reinsurer}`" :currency="currency"
                :url-sync="false" :open-on-click="false" compact-toolbar empty-text="No claim shares." />
        </section>

        <section>
            <h2 class="mb-2 text-section font-semibold">Cession movements</h2>
            <DataTable id="policy-ri-movements" label="Cession movements" :columns="movementColumns" :rows="data.movements" :row-key="(m) => m.id" :currency="currency"
                :url-sync="false" :open-on-click="false" compact-toolbar empty-text="No cession movements." />
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
