<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import RatingBreakdown from '@/components/rating/RatingBreakdown.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { AREAS, usePermissions } from '@/lib/permissions';
import { usePreferences } from '@/lib/preferences';
import type { ProposalData } from '@/lib/proposals';

/**
 * Phase 3 design §6 "Referral queue" (slice R5): proposals referred to underwriting, waiting ones first. The inspector shows why, the risk and the premium, and
 * lets an underwriter approve, approve with a loading (special terms, reason required) or decline (reason required). Nobody decides a proposal they prepared.
 */
interface Referral extends ProposalData { risk: { label_en: string; label_bn: string; value: string }[]; can_decide: boolean }
const props = defineProps<{ referrals: Referral[]; referralsTotal?: number; currency: string }>();

const preferences = usePreferences();
// GA-07: finance and administrators decide referrals but do not open the quotes screen.
const { can } = usePermissions();
const active = ref<string | null>(null);
const deciding = ref<Referral | null>(null);
const form = useForm({ decision: 'approve', loading_percent: '', reason: '' });
const errors = computed(() => form.errors as Record<string, string>);
const words = (v: string) => v.toLowerCase().replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());

function open(row: Referral, decision: 'approve' | 'approve_with_loading' | 'decline'): void {
    deciding.value = row;
    form.defaults({ decision, loading_percent: '', reason: '' });
    form.reset();
    form.clearErrors();
}
function submit(): void {
    form.post(`/underwriting/referrals/${deciding.value?.id}/decide`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });
}
const columns: DataColumn<Referral>[] = [
    { id: 'number', header: 'Proposal', value: (r) => r.number, href: (r) => `/proposals/${r.id}`, width: 170 },
    { id: 'customer', header: 'Customer', value: (r) => r.customer, width: 200 },
    { id: 'product', header: 'Product', value: (r) => r.product, width: 110 },
    { id: 'reasons', header: 'Referred because', value: (r) => r.referral_reasons.map((x) => words(x.code)).join(', '), width: 280 },
    { id: 'sum_insured', header: 'Sum insured', type: 'money', value: (r) => r.sum_insured },
    { id: 'gross', header: 'Gross premium', type: 'money', value: (r) => r.gross_premium },
    { id: 'submitted', header: 'Submitted', type: 'date', value: (r) => r.submitted_at?.slice(0, 10) },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status, filterOptions: ['submitted', 'approved', 'declined', 'issued'] },
];
const title = computed(() => (form.decision === 'decline' ? 'Decline' : form.decision === 'approve_with_loading' ? 'Approve with a loading' : 'Approve'));
</script>

<template>
    <AppLayout help="quotes" title="Referrals" fill>
        <QueueView data-tour="referrals-queue"
            id="underwriting-referrals"
            v-model:active="active"
            title="Referrals"
            :columns="columns"
            :rows="referrals"
            :total="referralsTotal ?? null"
            :row-key="(r) => r.id"
            :currency="currency"
            empty-text="No proposals are referred to underwriting."
            :empty-action="can(...AREAS.quotes) ? { label: 'Open quotes', href: '/quotations' } : null"
            :inspector-title="(r) => r.number"
            :inspector-subtitle="(r) => r.customer"
        >
            <template #details="{ row }">
                <h3 class="mb-1 text-ui font-medium">Referred because</h3>
                <ul class="mb-4 grid gap-1 text-ui">
                    <li v-for="reason in row.referral_reasons" :key="reason.code + reason.detail" class="border-l-2 border-warn pl-3"><span class="font-medium">{{ words(reason.code) }}.</span> {{ reason.detail }}</li>
                </ul>
                <DetailList
                    :items="[
                        { label: 'Status' },
                        { label: 'Product', value: `${row.product} · ${row.class_code}` },
                        { label: 'Cover starts', value: formatDate(row.inception) },
                        { label: 'Sum insured', value: `${formatMoney(row.sum_insured)} ${currency}`, num: true },
                        { label: 'Submitted by', value: row.submitted_by },
                        { label: 'Decided by', value: row.decided_by },
                        { label: 'Special terms', value: row.manual_loading ? `Loading ${row.manual_loading}%: ${row.manual_loading_reason}` : null },
                    ]"
                >
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div v-if="row.can_decide" class="mt-4 flex flex-wrap gap-2">
                    <Button @click="open(row, 'approve')">Approve</Button>
                    <Button variant="secondary" @click="open(row, 'approve_with_loading')">Approve with loading</Button>
                    <Button variant="ghost" @click="open(row, 'decline')">Decline</Button>
                </div>
                <p v-else-if="row.status === 'submitted'" class="mt-4 text-ui text-ink-2">Waiting for another underwriter: you prepared it, or the referral needs a different role.</p>
                <h3 class="mt-6 mb-1 text-ui font-medium">Risk</h3>
                <DetailList :items="row.risk.map((r) => ({ label: preferences.locale === 'bn' ? r.label_bn : r.label_en, value: r.value }))" />
                <h3 class="mt-6 mb-1 text-ui font-medium">Premium</h3>
                <RatingBreakdown :result="row.rating_result" :locale="preferences.locale" />
                <Link :href="`/proposals/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the proposal and its documents</Link>
            </template>
        </QueueView>

        <Drawer :open="deciding !== null" :title="`${title}: ${deciding?.number ?? ''}`" @update:open="(o) => !o && (deciding = null)">
            <p class="mb-4 text-ui text-ink-2">
                {{ form.decision === 'approve_with_loading' ? 'The premium is worked out again with the loading; the loading and its reason show on the schedule as special terms.' : form.decision === 'decline' ? 'The customer is told the proposal was declined. Say why.' : 'Approve within your underwriting limit.' }}
            </p>
            <FormLayout :submit-label="title" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="deciding = null">
                <Field v-if="form.decision === 'approve_with_loading'" id="loading_percent" label="Loading (%)" hint="For example 12.50." :error="errors.loading_percent">
                    <TextInput id="loading_percent" v-model="form.loading_percent" inputmode="decimal" />
                </Field>
                <Field id="decision_reason" label="Reason" :optional="form.decision === 'approve'" :error="errors.reason"><TextInput id="decision_reason" v-model="form.reason" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
