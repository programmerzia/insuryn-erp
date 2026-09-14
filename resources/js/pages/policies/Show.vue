<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import EndorseDetailsDrawer, { type InsuredDetails } from '@/components/policies/EndorseDetailsDrawer.vue';
import WriteOffDrawer, { type WriteOffOutlook } from '@/components/policies/WriteOffDrawer.vue';
import EndorseRiskDrawer from '@/components/rating/EndorseRiskDrawer.vue';
import RatingBreakdown from '@/components/rating/RatingBreakdown.vue';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import type { AccountingJournal, AuditRow, DocumentGeneration, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { dateWithin } from '@/lib/drawerDefaults';
import { formatDate, formatMoney } from '@/lib/format';
import { basisSentence, changeRows, type EndorsementRatingData } from '@/lib/endorsement';
import { useMoneyForm } from '@/lib/moneyForm';
import { policyPageActions, usePageActions } from '@/lib/pageActions';
import { usePreferences } from '@/lib/preferences';
import type { RatingResultData, RiskFieldDefinition } from '@/lib/riskForm';

const props = defineProps<{
    policy: { id: string; number: string | null; status: string; version: number; inception: string; expiry: string; channel: string; currency: string; policyholder: string;
        product_code: string; agent_code: string | null; gross_premium: string; net_premium: string; tax: string; stamp_duty: string; cancel_date: string | null };
    transactions: { id: string; type: string; effective_date: string; premium_delta: string; reason: string | null; endorsement_kind?: string | null }[];
    /** Gap fixes W7 (GA-25): the name, address, mortgagee and contact details the policy carries now. */
    insuredDetails?: InsuredDetails;
    installments: { id: string; no: number; label: string; payer: string; due_date: string; amount: string; paid: string; credited: string; outstanding: string; status: string }[];
    payers: { name: string; share_percent: string; billed: string; paid: string; outstanding: string }[];
    actions: { issue: boolean; record_receipt: boolean; endorse: boolean; endorse_risk: boolean; cancel: boolean; lapse: boolean; reinstate: boolean; renew: boolean; refund: boolean; write_off?: boolean; endorse_details?: boolean };
    /** Gap fixes W7 (GA-24): a cancelled policy's unpaid premium and the write-off waiting for approval; null otherwise. */
    writeOff?: WriteOffOutlook | null;
    /** Slice R7: the frozen rating of a policy issued from a proposal; null for products without a rating plan. */
    rating: {
        result: RatingResultData; risk: { label_en: string; label_bn: string; value: string }[]; special_terms: string[]; issue_basis: string | null; premium_received_reference: string | null;
        proposal: { id: string; number: string } | null; quotation: { id: string; number: string } | null; uses_current_tariff: boolean;
        endorsements: { id: string; effective_date: string; reason: string | null; rating: EndorsementRatingData }[];
        schema: RiskFieldDefinition[]; current_inputs: Record<string, unknown>; coverages: { code: string; name_en: string; name_bn: string; mandatory: boolean }[]; chosen_coverages: string[];
    } | null;
    today: string;
    /** Gap fix GA-14. */
    bouncedPremium?: { receipt_id: string; receipt_number: string; cheque_no: string | null; bounced_on: string; bounce_reason: string | null; installment_no: number; outstanding: string }[];
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
    documents?: StoredDocumentRow[];
    documentUpload: string | null;
    documentGeneration?: DocumentGeneration;
}>();

const base = `/policies/${props.policy.id}`;
const drawer = ref<'issue' | 'endorse' | 'cancel' | 'lapse' | 'reinstate' | null>(null);
const endorseRiskOpen = ref(false);
const writeOffOpen = ref(false);
const detailsOpen = ref(false);
const detailWords: Record<string, string> = { name: 'name', address: 'address', mortgagee: 'mortgagee', contact: 'contact details' };
const preferences = usePreferences();
const issuedOn = (basis: string | null, reference: string | null) => (basis === 'credit' ? 'Issued on credit' : basis === 'premium_received' ? `Premium received, reference ${reference}` : null);
const close = () => (drawer.value = null);
const title = computed(() => props.policy.number ?? 'Quote');
const issue = useMoneyForm(() => `${base}/issue`, { on: props.policy.inception }, close);
// Gap fix GA-19 (GA-24): an endorsement and a cancellation take effect today (kept inside the cover) unless changed.
const endorse = useMoneyForm(() => `${base}/endorse`, { effective_date: dateWithin(props.today, props.policy.inception, props.policy.expiry), premium_delta: '', reason: '' }, close);
const cancel = useMoneyForm(() => `${base}/cancel`, { cancel_date: dateWithin(props.today, props.policy.inception, props.policy.expiry), reason: '' }, close);
const transition = useForm({ reason: '' });
const active = computed(() => (drawer.value === 'issue' ? issue : drawer.value === 'endorse' ? endorse : drawer.value === 'cancel' ? cancel : null));
const words = (v: string) => v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const facts = computed(() => [
    { label: `Gross premium (${props.policy.currency})`, value: formatMoney(props.policy.gross_premium), num: true },
    { label: 'Net premium', value: formatMoney(props.policy.net_premium), num: true },
    { label: 'Tax', value: formatMoney(props.policy.tax), num: true },
    ...(props.rating ? [{ label: 'Stamp duty', value: formatMoney(props.policy.stamp_duty), num: true }] : []),
    { label: 'Cover', value: `${formatDate(props.policy.inception)} to ${formatDate(props.policy.expiry)}` },
]);
const outstanding = computed(() => props.installments.reduce((sum, i) => sum + Number(i.outstanding !== '0.00'), 0));
// GA-40: installments in the shared table — sort, filter, totals and export; filters stay out of the page URL.
type InstallmentRow = (typeof props.installments)[number];
const installmentColumns: DataColumn<InstallmentRow>[] = [
    { id: 'no', header: 'No', value: (i) => i.label, width: 72 },
    { id: 'payer', header: 'Payer', value: (i) => i.payer, width: 180 },
    { id: 'due', header: 'Due', type: 'date', value: (i) => i.due_date },
    { id: 'amount', header: 'Amount', type: 'money', value: (i) => i.amount, total: true },
    { id: 'paid', header: 'Paid', type: 'money', value: (i) => i.paid, total: true },
    { id: 'credited', header: 'Credited', type: 'money', value: (i) => i.credited, total: true },
    { id: 'outstanding', header: 'Outstanding', type: 'money', value: (i) => i.outstanding, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (i) => i.status, filterOptions: [...new Set(props.installments.map((i) => i.status))] },
];

async function renew(): Promise<void> {
    if (await confirmAction({ title: `Renew ${title.value}?`, body: 'A renewal quote is created for the next term with the same product, policyholder, producer and payers.', confirmLabel: 'Create renewal quote' })) {
        router.post(`${base}/renew`, {}, { preserveScroll: true });
    }
}
// GA-29: the actions this page allows, offered in the command palette too.
usePageActions(() => ({ group: `This policy`, actions: policyPageActions(title.value, props.policy.id, props.actions, {
    endorse: () => (drawer.value = 'endorse'), endorseRisk: () => (endorseRiskOpen.value = true), cancel: () => (drawer.value = 'cancel'), renew: () => void renew(), issue: () => (drawer.value = 'issue'),
}) }));
</script>

<template>
    <AppLayout help="policies" :title="title">
        <ObjectPage
            :title="title"
            :subtitle="`${policy.policyholder} · ${policy.product_code}${policy.agent_code ? ` · producer ${policy.agent_code}` : ' · direct'} · version ${policy.version}${policy.cancel_date ? ` · cancelled from ${formatDate(policy.cancel_date)}` : ''}`"
            :status="policy.status"
            :facts="facts"
            :crumbs="[{ label: 'Policies', href: '/policies' }]"
            :currency="policy.currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
            :document-generation="documentGeneration"
        >
            <template #actions>
                <button v-if="actions.endorse" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'endorse'">Endorse</button>
                <button v-if="actions.endorse_risk" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="endorseRiskOpen = true">Endorse</button>
                <button v-if="actions.endorse_details && insuredDetails" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="detailsOpen = true">Change details</button>
                <button v-if="actions.lapse" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'lapse'">Lapse</button>
                <button v-if="actions.reinstate" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'reinstate'">Reinstate</button>
                <button v-if="actions.renew" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="renew">Renew</button>
                <Link v-if="actions.refund" :href="`/refunds?policy=${policy.id}`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Request refund</Link>
                <button v-if="actions.cancel" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="drawer = 'cancel'">Cancel policy</button>
                <button v-if="actions.issue" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="drawer = 'issue'">Issue policy</button>
                <button v-if="actions.write_off && writeOff?.within_limit && !writeOff.pending" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="writeOffOpen = true">Write off small balance</button>
                <Link v-if="actions.record_receipt" :href="`/receipts/create?policy=${policy.id}`" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Record receipt</Link>
            </template>
            <template #overview>
                <!-- Gap fix GA-14: no premium, no cover — premium a bounced cheque was paying is unpaid again. -->
                <div v-if="bouncedPremium?.length" class="mb-4 max-w-[1000px] border-l-2 border-danger bg-surface-2 px-3 py-2 text-ui" role="alert">
                    <p class="font-medium">Premium cheque bounced: no premium, no cover.</p>
                    <ul class="mt-1 grid gap-0.5">
                        <li v-for="b in bouncedPremium" :key="`${b.receipt_id}-${b.installment_no}`">
                            Cheque {{ b.cheque_no ?? '' }} on <Link :href="`/receipts/${b.receipt_id}`" class="text-accent-text hover:underline">{{ b.receipt_number }}</Link> bounced {{ formatDate(b.bounced_on) }}<template v-if="b.bounce_reason"> ({{ b.bounce_reason }})</template>:
                            installment #{{ b.installment_no }} has {{ formatMoney(b.outstanding) }} {{ policy.currency }} unpaid.
                        </li>
                    </ul>
                    <p class="mt-1 text-ink-2">The first payment reminder went out when it bounced. Take the premium again, or cancel the policy if the customer does not pay.</p>
                </div>
                <p v-if="writeOff?.pending" class="mb-4 max-w-[1000px] border-l-2 border-warn pl-3 text-ui" role="status">
                    A write-off of {{ formatMoney(writeOff.pending.requested) }} {{ policy.currency }} is waiting for approval in the approvals inbox. Nothing is posted until it is approved.
                </p>
                <h2 class="mb-2 text-ui font-medium">Installments <span class="font-normal text-ink-2">· {{ outstanding }} with money outstanding</span></h2>
                <div class="mb-6 max-w-[1000px] border border-line" data-testid="policy-installments">
                    <DataTable :id="`policy-installments`" label="Installments" :columns="installmentColumns" :rows="installments" :row-key="(i) => i.id" :currency="policy.currency" :url-sync="false"
                        :open-on-click="false" compact-toolbar empty-text="Installments are created when the policy is issued." />
                </div>
                <h2 class="mb-2 text-ui font-medium">Payers</h2>
                <div class="max-w-[760px] overflow-x-auto border border-line">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense max-sm:min-w-[36rem]">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">Payer</th><th class="w-24 border-b border-line px-3 text-right font-medium">Share (%)</th><th class="w-32 border-b border-line px-3 text-right font-medium">Billed</th><th class="w-32 border-b border-line px-3 text-right font-medium">Paid</th><th class="w-32 border-b border-line px-3 text-right font-medium">Outstanding</th></tr></thead>
                        <tbody><tr v-for="p in payers" :key="p.name" class="h-(--row-h)"><td class="border-b border-line px-3">{{ p.name }}</td><td class="num border-b border-line px-3">{{ p.share_percent }}</td><td class="num border-b border-line px-3">{{ formatMoney(p.billed) }}</td><td class="num border-b border-line px-3">{{ formatMoney(p.paid) }}</td><td class="num border-b border-line px-3">{{ formatMoney(p.outstanding) }}</td></tr></tbody>
                    </table>
                </div>
            </template>
            <template v-if="rating" #rating>
                <div class="grid max-w-[1100px] gap-8 lg:grid-cols-[minmax(0,1fr)_380px]">
                    <div class="grid content-start gap-6">
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Issued on</h2>
                            <DetailList
                                :items="[
                                    { label: 'Tariff', value: `${rating.result.plan.code} version ${rating.result.plan.version}, rated for ${formatDate(rating.result.as_of)}` },
                                    { label: 'Proposal' },
                                    { label: 'Quotation' },
                                    { label: 'Premium', value: issuedOn(rating.issue_basis, rating.premium_received_reference) },
                                    { label: 'Endorsements re-rate on', value: rating.uses_current_tariff ? 'The tariff in force on their date' : 'This tariff version' },
                                ]"
                            >
                                <template #Proposal><Link v-if="rating.proposal" :href="`/proposals/${rating.proposal.id}`" class="text-accent-text hover:underline">{{ rating.proposal.number }}</Link></template>
                                <template #Quotation><Link v-if="rating.quotation" :href="`/quotations/${rating.quotation.id}`" class="text-accent-text hover:underline">{{ rating.quotation.number }}</Link></template>
                            </DetailList>
                        </section>
                        <section v-if="rating.special_terms.length">
                            <h2 class="mb-2 text-ui font-medium">Special terms</h2>
                            <ul class="grid gap-1 text-ui"><li v-for="term in rating.special_terms" :key="term" class="border-l-2 border-warn pl-3">{{ term }}</li></ul>
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Risk at issue</h2>
                            <DetailList :items="rating.risk.map((r) => ({ label: preferences.locale === 'bn' ? r.label_bn : r.label_en, value: r.value }))" />
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Endorsement re-ratings</h2>
                            <p v-if="rating.endorsements.length === 0" class="text-ui text-ink-2">No endorsement has changed the risk.</p>
                            <div v-for="e in rating.endorsements" :key="e.id" class="mb-4 border border-line">
                                <p class="border-b border-line bg-surface-2 px-3 py-1.5 text-ui"><span class="font-medium">From {{ formatDate(e.effective_date) }}</span><template v-if="e.reason"> · {{ e.reason }}</template></p>
                                <table class="w-full text-dense">
                                    <thead class="text-ink-2"><tr><th class="px-3 py-1 text-left font-medium" /><th class="px-3 py-1 text-right font-medium">Before</th><th class="px-3 py-1 text-right font-medium">Re-rated</th><th class="px-3 py-1 text-right font-medium">Charged ({{ policy.currency }})</th></tr></thead>
                                    <tbody><tr v-for="row in changeRows(e.rating)" :key="row.label" class="border-t border-line"><td class="px-3 py-1">{{ row.label }}</td><td class="num px-3 py-1">{{ row.before }}</td><td class="num px-3 py-1">{{ row.after }}</td><td class="num px-3 py-1 font-medium">{{ row.change }}</td></tr></tbody>
                                </table>
                                <p class="px-3 py-1.5 text-dense text-ink-2">{{ basisSentence(e.rating) }}</p>
                            </div>
                        </section>
                    </div>
                    <aside class="h-fit rounded-panel border border-line bg-surface-2 p-4" aria-label="Premium at issue">
                        <h2 class="mb-2 text-ui font-medium">Premium at issue <span class="font-normal text-ink-2">· frozen</span></h2>
                        <RatingBreakdown :result="rating.result" :locale="preferences.locale" />
                    </aside>
                </div>
            </template>
            <template #transactions>
                <div class="max-w-[900px] overflow-x-auto border border-line">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense max-sm:min-w-[36rem]">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="w-40 border-b border-line px-3 text-left font-medium">Transaction</th><th class="w-32 border-b border-line px-3 text-left font-medium">Effective</th><th class="w-40 border-b border-line px-3 text-right font-medium">Premium change ({{ policy.currency }})</th><th class="border-b border-line px-3 text-left font-medium">Reason</th></tr></thead>
                        <tbody><tr v-for="t in transactions" :key="t.id" class="h-(--row-h)"><td class="border-b border-line px-3">{{ words(t.type) }}<span v-if="t.endorsement_kind" class="text-ink-2"> · {{ detailWords[t.endorsement_kind] ?? t.endorsement_kind }}</span></td><td class="border-b border-line px-3">{{ formatDate(t.effective_date) }}</td><td class="num border-b border-line px-3">{{ formatMoney(t.premium_delta) }}</td><td class="truncate border-b border-line px-3 text-ink-2">{{ t.reason }}</td></tr></tbody>
                    </table>
                </div>
            </template>
        </ObjectPage>

        <Drawer :open="drawer === 'issue'" :title="`Issue ${title}`" @update:open="(o) => !o && close()">
            <FormLayout submit-label="Review and issue" :dirty="issue.form.isDirty" :processing="issue.form.processing" :error="(issue.form.errors as Record<string, string>).form" @submit="issue.review" @cancel="close">
                <Field id="on" label="Issue date" :error="issue.form.errors.on"><DateInput v-model="issue.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'endorse'" :title="`Endorse ${title}`" @update:open="(o) => !o && close()">
            <FormLayout submit-label="Review and post" :dirty="endorse.form.isDirty" :processing="endorse.form.processing" :error="(endorse.form.errors as Record<string, string>).form" @submit="endorse.review" @cancel="close">
                <Field id="effective_date" label="Effective from" :error="endorse.form.errors.effective_date"><DateInput v-model="endorse.form.effective_date" /></Field>
                <Field id="premium_delta" :label="`Premium change (${policy.currency})`" hint="Negative for a decrease, like -1,000.00." :error="endorse.form.errors.premium_delta"><MoneyInput v-model="endorse.form.premium_delta" allow-negative /></Field>
                <Field id="reason" label="Reason" :error="endorse.form.errors.reason"><TextInput v-model="endorse.form.reason" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'cancel'" :title="`Cancel ${title}`" @update:open="(o) => !o && close()">
            <FormLayout submit-label="Review cancellation" :dirty="cancel.form.isDirty" :processing="cancel.form.processing" :error="(cancel.form.errors as Record<string, string>).form" @submit="cancel.review" @cancel="close">
                <Field id="cancel_date" label="Cancel from" hint="Earned premium up to this date stays; the rest is credited or refunded." :error="cancel.form.errors.cancel_date"><DateInput v-model="cancel.form.cancel_date" /></Field>
                <Field id="cancel_reason" label="Reason" :error="cancel.form.errors.reason"><TextInput v-model="cancel.form.reason" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'lapse' || drawer === 'reinstate'" :title="drawer === 'lapse' ? `Lapse ${title}` : `Reinstate ${title}`" @update:open="(o) => !o && close()">
            <FormLayout :submit-label="drawer === 'lapse' ? 'Lapse policy' : 'Reinstate policy'" :dirty="transition.isDirty" :processing="transition.processing" :error="(transition.errors as Record<string, string>).form" @submit="transition.post(`${base}/${drawer}`, { onSuccess: close })" @cancel="close">
                <Field id="transition_reason" label="Reason" :error="transition.errors.reason"><TextInput v-model="transition.reason" /></Field>
            </FormLayout>
        </Drawer>
        <EndorseDetailsDrawer v-if="actions.endorse_details && insuredDetails" v-model:open="detailsOpen" :policy-id="policy.id" :title="title" :today="today" :details="insuredDetails" />
        <WriteOffDrawer v-if="actions.write_off && writeOff" v-model:open="writeOffOpen" :policy-id="policy.id" :title="title" :currency="policy.currency" :outlook="writeOff" />
        <EndorseRiskDrawer
            v-if="rating && actions.endorse_risk"
            v-model:open="endorseRiskOpen"
            :policy-id="policy.id"
            :title="title"
            :currency="policy.currency"
            :locale="preferences.locale"
            :inception="policy.inception"
            :expiry="policy.expiry"
            :today="today"
            :schema="rating.schema"
            :inputs="rating.current_inputs"
            :coverages="rating.coverages"
            :chosen="rating.chosen_coverages"
        />
        <JournalPreviewDialog v-if="active" v-model:open="active.previewOpen.value" :result="active.preview.value" :title="drawer === 'issue' ? `Issue ${title}?` : drawer === 'endorse' ? `Post the endorsement of ${title}?` : `Cancel ${title}?`"
            :confirm-label="drawer === 'issue' ? 'Issue and post' : drawer === 'endorse' ? 'Post endorsement' : 'Cancel and post'" :currency="policy.currency" :processing="active.form.processing" @confirm="active.post" />
    </AppLayout>
</template>
