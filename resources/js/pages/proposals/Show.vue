<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import RatingBreakdown from '@/components/rating/RatingBreakdown.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DateInput from '@/components/forms/DateInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';
import { proposalPageActions, usePageActions } from '@/lib/pageActions';
import { usePreferences } from '@/lib/preferences';
import type { ProposalData } from '@/lib/proposals';
import { formFields, initialValues, localProblems, type RiskFieldDefinition, riskInputs } from '@/lib/riskForm';

/**
 * Proposal page (Phase 3 design §2 step 2, slice R5): the accepted quotation's terms and premium, KYC, documents, and the underwriting outcome — approved
 * automatically, or referred with its reasons and decided in the referral queue (with any special-terms loading).
 */
const props = defineProps<{
    proposal: ProposalData; risk: { label_en: string; label_bn: string; value: string }[]; kycIdTypes: { value: string; label: string }[];
    /** Flow fix X7: the risk details needed only for the proposal (a chassis number), which ones are still empty, and whether they can be entered here. */
    riskDetails: { fields: RiskFieldDefinition[]; values: Record<string, unknown>; missing: string[]; editable: boolean };
    can: { verify_kyc: boolean; waive_kyc: boolean; submit: boolean; decide: boolean; issue_cover_note: boolean; issue_policy: boolean };
    /** Slice R7: issuing the policy — whether the product issues on credit, until when the quotation's premium holds, the policy once issued. */
    policyIssue: { allow_credit: boolean; valid_until: string | null; policy: { id: string; number: string } | null };
    today: string; coverNoteMaxDays: number; coverNotes: { id: string; number: string; status: string; valid_from: string; valid_to: string; cancel_reason: string | null }[];
    documentUpload: string | null; timeline?: TimelineEntry[]; accounting?: AccountingJournal[]; audit?: AuditRow[]; documents?: StoredDocumentRow[];
}>();

const preferences = usePreferences();
const p = computed(() => props.proposal);
const base = `/proposals/${props.proposal.id}`;
const kycOpen = ref<'verify' | 'waive' | null>(null);
const kyc = useForm({ action: 'verify', id_type: props.kycIdTypes[0]?.value ?? '', id_number: '', reason: '' });
function saveKyc(): void {
    kyc.action = kycOpen.value ?? 'verify';
    kyc.post(`${base}/kyc`, { preserveScroll: true, onSuccess: () => (kycOpen.value = null) });
}
// Flow fix X7: details the quote could do without are entered on the draft proposal; Submit opens them first while one is missing.
const detailsOpen = ref(false);
const detailFields = computed(() => formFields(props.riskDetails.fields, preferences.locale, 'proposal'));
const details = useForm({ risk_inputs: {} as Record<string, string | number | boolean> });
const detailValues = ref(initialValues(props.riskDetails.fields, props.riskDetails.values));
const detailTouched = ref(false);
const detailError = (key: string) => (details.errors as Record<string, string>)[`risk_inputs.${key}`] ?? (detailTouched.value ? localProblems(props.riskDetails.fields, detailValues.value, 'proposal')[key] : undefined);
const detailText = (key: string) => String(detailValues.value[key] ?? '');
function saveDetails(): void {
    detailTouched.value = true;
    if (Object.keys(localProblems(props.riskDetails.fields, detailValues.value, 'proposal')).length > 0) return;
    details.risk_inputs = riskInputs(props.riskDetails.fields, detailValues.value);
    details.post(`${base}/risk-details`, { preserveScroll: true, onSuccess: () => (detailsOpen.value = false) });
}
async function submit(): Promise<void> {
    if (props.riskDetails.missing.length > 0 && props.riskDetails.editable) {
        detailTouched.value = true;
        detailsOpen.value = true;
        return;
    }
    const pending = p.value.kyc_status === 'pending' ? ' KYC is not verified, so it will be referred.' : '';
    if (await confirmAction({ title: `Submit ${p.value.number}?`, body: `The underwriting rules decide whether it is approved now or referred to an underwriter.${pending}`, confirmLabel: 'Submit proposal' })) {
        router.post(`${base}/submit`, {}, { preserveScroll: true });
    }
}
// Slice R6: a cover note from the cover start (or today), for at most the class's number of days including both ends.
const addDays = (day: string, days: number) => new Date(Date.parse(`${day}T00:00:00Z`) + days * 86_400_000).toISOString().slice(0, 10);
const coverOpen = ref(false);
const coverStart = props.proposal.inception > props.today ? props.proposal.inception : props.today;
// GA-28: a cover note needs the premium received (with its reference) unless the product issues on credit, as the policy does.
const cover = useForm({ valid_from: coverStart, valid_to: addDays(coverStart, props.coverNoteMaxDays - 1), premium_received: !props.policyIssue.allow_credit, premium_reference: '' });
function issueCoverNote(): void {
    cover.post(`${base}/cover-notes`, { preserveScroll: true, onSuccess: () => (coverOpen.value = false) });
}
// Slice R7: issue the policy (design §2 step 4) — premium received with its reference unless the product issues on credit; the journal is shown before posting.
const issueOpen = ref(false);
const issue = useMoneyForm(() => `${base}/issue-policy`, { on: props.today, installment_count: 1, premium_received: !props.policyIssue.allow_credit, premium_reference: '' }, () => (issueOpen.value = false));
const words = (v: string) => v.toLowerCase().replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
// GA-29: the actions this page allows, offered in the command palette too.
usePageActions(() => ({ group: 'This proposal', actions: proposalPageActions(p.value.number, props.can, {
    submit: () => void submit(), coverNote: () => (coverOpen.value = true), issuePolicy: () => (issueOpen.value = true),
}) }));
const facts = computed(() => [
    { label: `Gross premium (${p.value.currency})`, value: formatMoney(p.value.gross_premium), num: true },
    { label: 'Sum insured', value: formatMoney(p.value.sum_insured), num: true },
    { label: 'Cover starts', value: formatDate(p.value.inception) },
    { label: 'Underwriting', value: p.value.underwriting_status ? words(p.value.underwriting_status) : 'Not submitted' },
]);
</script>

<template>
    <AppLayout help="quotes" :title="proposal.number">
        <ObjectPage
            :title="proposal.number"
            :subtitle="`${proposal.customer} · ${proposal.product} · ${proposal.producer ? `producer ${proposal.producer}` : 'direct'} · from quotation ${proposal.quotation.number}`"
            :status="proposal.status"
            :facts="facts"
            :crumbs="[{ label: 'Quotes', href: '/quotations' }, { label: proposal.quotation.number, href: `/quotations/${proposal.quotation.id}` }]"
            :currency="proposal.currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
        >
            <template #actions>
                <button v-if="can.verify_kyc" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="kycOpen = 'verify'">Verify identity</button>
                <button v-if="can.waive_kyc" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="kycOpen = 'waive'">Waive KYC</button>
                <Link v-if="can.decide" href="/underwriting/referrals" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Open referrals</Link>
                <button v-if="can.issue_cover_note" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="coverOpen = true">Issue cover note</button>
                <Link v-if="policyIssue.policy" :href="`/policies/${policyIssue.policy.id}`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Open policy {{ policyIssue.policy.number }}</Link>
                <button v-if="can.issue_policy" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="issueOpen = true">Issue policy</button>
                <button v-if="riskDetails.editable" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="detailsOpen = true">Enter risk details</button>
                <button v-if="can.submit" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="submit">Submit to underwriting</button>
            </template>
            <template #overview>
                <div class="grid max-w-[1100px] gap-8 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="grid content-start gap-6">
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Underwriting</h2>
                            <p v-if="!proposal.underwriting_status" class="text-ui text-ink-2">Not submitted yet. Verify the customer's identity and attach the documents first.</p>
                            <p v-else-if="proposal.underwriting_status === 'auto_approved'" class="text-ui">Approved automatically: no referral reason applied.</p>
                            <template v-else>
                                <ul class="mb-2 grid gap-1 text-ui">
                                    <li v-for="reason in proposal.referral_reasons" :key="reason.code + reason.detail" class="border-l-2 border-warn pl-3">
                                        <span class="font-medium">{{ words(reason.code) }}.</span> {{ reason.detail }}
                                    </li>
                                </ul>
                                <p v-if="proposal.decided_by" class="text-ui"><StatusBadge :status="proposal.underwriting_status" /> by {{ proposal.decided_by }}<template v-if="proposal.decision_reason">: {{ proposal.decision_reason }}</template></p>
                            </template>
                            <p v-if="!proposal.underwriting_status && riskDetails.missing.length" class="mt-1 border-l-2 border-warn pl-3 text-ui">Before submitting, enter the {{ riskDetails.missing.map((m) => m.toLowerCase()).join(', ') }}.</p>
                            <p v-if="proposal.manual_loading" class="mt-2 text-ui">Special terms: loading {{ proposal.manual_loading }}% — {{ proposal.manual_loading_reason }}</p>
                        </section>
                        <section v-if="coverNotes.length">
                            <h2 class="mb-2 text-ui font-medium">Cover notes</h2>
                            <ul class="grid gap-1 text-ui">
                                <li v-for="note in coverNotes" :key="note.id" class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ note.number }}</span><StatusBadge :status="note.status" />
                                    <span class="text-ink-2">{{ formatDate(note.valid_from) }} to {{ formatDate(note.valid_to) }}</span>
                                    <span v-if="note.cancel_reason" class="text-ink-2">· {{ note.cancel_reason }}</span>
                                </li>
                            </ul>
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Know your customer</h2>
                            <DetailList
                                :items="[
                                    { label: 'Status' },
                                    { label: 'Identity document', value: proposal.kyc_id_type ? `${proposal.kyc_id_type} ${proposal.kyc_id_number}` : null },
                                    { label: 'Waiver reason', value: proposal.kyc_waiver_reason },
                                    { label: 'Recorded by', value: proposal.kyc_by },
                                ]"
                            >
                                <template #Status><StatusBadge :status="proposal.kyc_status" /></template>
                            </DetailList>
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Risk</h2>
                            <DetailList :items="risk.map((r) => ({ label: preferences.locale === 'bn' ? r.label_bn : r.label_en, value: r.value }))" />
                        </section>
                    </div>
                    <aside class="h-fit rounded-panel border border-line bg-surface-2 p-4" aria-label="Premium">
                        <h2 class="mb-2 text-ui font-medium">Premium</h2>
                        <RatingBreakdown :result="proposal.rating_result" :locale="preferences.locale" />
                    </aside>
                </div>
            </template>
        </ObjectPage>

        <Drawer v-model:open="issueOpen" :title="`Issue the policy for ${proposal.number}`">
            <p class="mb-4 text-ui text-ink-2">
                The policy is issued on this proposal's premium, {{ proposal.currency }} {{ formatMoney(proposal.gross_premium) }}, frozen from its rating<template v-if="policyIssue.valid_until"> (the quotation holds it until {{ formatDate(policyIssue.valid_until) }})</template>. Any cover note is superseded.
            </p>
            <FormLayout submit-label="Review and issue" :dirty="issue.form.isDirty" :processing="issue.form.processing" :error="(issue.form.errors as Record<string, string>).form" @submit="issue.review" @cancel="issueOpen = false">
                <Field id="issue_on" label="Issue date" :error="issue.form.errors.on"><DateInput id="issue_on" v-model="issue.form.on" /></Field>
                <Field id="installment_count" label="Installments" :error="issue.form.errors.installment_count">
                    <SelectInput id="installment_count" :model-value="String(issue.form.installment_count)" :options="['1', '2', '3', '4', '6', '12'].map((n) => ({ value: n, label: n === '1' ? 'Paid at once' : `${n} installments` }))" @update:model-value="(v) => (issue.form.installment_count = Number(v))" />
                </Field>
                <Field id="premium_received" :label="policyIssue.allow_credit ? 'Premium' : 'Premium received'" :optional="policyIssue.allow_credit" :hint="policyIssue.allow_credit ? 'This product may be issued on credit.' : 'This product is not issued on credit: confirm the premium was received.'">
                    <label class="flex items-center gap-2 text-ui"><input id="premium_received" v-model="issue.form.premium_received" type="checkbox" class="size-3.5 accent-accent" />The premium was received</label>
                </Field>
                <Field v-if="issue.form.premium_received" id="premium_reference" label="Reference" hint="Receipt, bank transfer or cheque reference." :error="issue.form.errors.premium_reference">
                    <TextInput id="premium_reference" v-model="issue.form.premium_reference" :maxlength="128" />
                </Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="issue.previewOpen.value" :result="issue.preview.value" :title="`Issue the policy for ${proposal.number}?`" confirm-label="Issue and post" :currency="proposal.currency" :processing="issue.form.processing" @confirm="issue.post" />
        <Drawer v-model:open="coverOpen" :title="`Issue a cover note for ${proposal.number}`">
            <p class="mb-4 text-ui text-ink-2">Temporary evidence of cover until the policy is issued, for at most {{ coverNoteMaxDays }} days. Nothing is posted to the accounts.</p>
            <FormLayout submit-label="Issue cover note" :dirty="cover.isDirty" :processing="cover.processing" :error="(cover.errors as Record<string, string>).form" @submit="issueCoverNote" @cancel="coverOpen = false">
                <Field id="valid_from" label="Cover from" :error="cover.errors.valid_from"><DateInput id="valid_from" v-model="cover.valid_from" /></Field>
                <Field id="valid_to" label="Cover until" :hint="`Included. At most ${coverNoteMaxDays} days.`" :error="cover.errors.valid_to"><DateInput id="valid_to" v-model="cover.valid_to" /></Field>
                <Field id="cover_premium_received" :label="policyIssue.allow_credit ? 'Premium' : 'Premium received'" :optional="policyIssue.allow_credit" :hint="policyIssue.allow_credit ? 'This product issues on credit; tick if the premium was already paid.' : 'This product is not issued on credit, so neither is its cover note.'">
                    <label class="flex items-center gap-2 text-ui"><input id="cover_premium_received" v-model="cover.premium_received" type="checkbox" :disabled="!policyIssue.allow_credit" class="size-3.5 accent-accent" />The premium was received</label>
                </Field>
                <Field v-if="cover.premium_received" id="cover_premium_reference" label="Reference" hint="Receipt, bank transfer or cheque reference." :error="cover.errors.premium_reference">
                    <TextInput id="cover_premium_reference" v-model="cover.premium_reference" :maxlength="128" />
                </Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="detailsOpen" :title="`Risk details for ${proposal.number}`">
            <p class="mb-4 text-ui text-ink-2">Details the underwriters need that did not change the price. They are checked against the quotation's rating, so the premium stays as quoted.</p>
            <FormLayout submit-label="Save details" :dirty="details.isDirty || detailTouched" :processing="details.processing" :error="(details.errors as Record<string, string>).form" @submit="saveDetails" @cancel="detailsOpen = false">
                <template v-for="field in detailFields" :key="field.key">
                    <Field v-if="field.type === 'boolean'" :id="`detail_${field.key}`" :label="field.label" optional>
                        <label class="flex items-center gap-2 text-ui"><input :id="`detail_${field.key}`" v-model="detailValues[field.key]" type="checkbox" class="size-3.5 accent-accent" />Yes</label>
                    </Field>
                    <Field v-else :id="`detail_${field.key}`" :label="field.label" :optional="!field.required" :hint="field.hint ?? undefined" :error="detailError(field.key)">
                        <SelectInput v-if="field.type === 'select'" :id="`detail_${field.key}`" :model-value="detailText(field.key)" placeholder="Choose" :options="field.options" @update:model-value="(v) => (detailValues[field.key] = v ?? '')" />
                        <DateInput v-else-if="field.type === 'date'" :id="`detail_${field.key}`" :model-value="detailText(field.key)" @update:model-value="(v) => (detailValues[field.key] = v ?? '')" />
                        <TextInput v-else :id="`detail_${field.key}`" :model-value="detailText(field.key)" :maxlength="field.maxLength ?? undefined" @update:model-value="(v) => (detailValues[field.key] = String(v))" />
                    </Field>
                </template>
            </FormLayout>
        </Drawer>
        <Drawer :open="kycOpen !== null" :title="kycOpen === 'waive' ? 'Waive KYC' : 'Verify identity'" @update:open="(o) => !o && (kycOpen = null)">
            <FormLayout :submit-label="kycOpen === 'waive' ? 'Waive KYC' : 'Record verification'" :dirty="kyc.isDirty" :processing="kyc.processing" :error="(kyc.errors as Record<string, string>).form" @submit="saveKyc" @cancel="kycOpen = null">
                <template v-if="kycOpen === 'verify'">
                    <Field id="id_type" label="Identity document" :error="kyc.errors.id_type"><SelectInput id="id_type" v-model="kyc.id_type" :options="kycIdTypes" /></Field>
                    <Field id="id_number" label="Document number" hint="As printed on the document you checked." :error="kyc.errors.id_number"><TextInput id="id_number" v-model="kyc.id_number" :maxlength="64" /></Field>
                </template>
                <Field v-else id="kyc_reason" label="Why KYC is waived" :error="kyc.errors.reason"><TextInput id="kyc_reason" v-model="kyc.reason" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
