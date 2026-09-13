<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import RatingBreakdown from '@/components/rating/RatingBreakdown.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate, formatMoney } from '@/lib/format';
import { usePreferences } from '@/lib/preferences';
import type { ProposalData } from '@/lib/proposals';

/**
 * Proposal page (Phase 3 design §2 step 2, slice R5): the accepted quotation's terms and premium, KYC, documents, and the underwriting outcome — approved
 * automatically, or referred with its reasons and decided in the referral queue (with any special-terms loading).
 */
const props = defineProps<{
    proposal: ProposalData; risk: { label_en: string; label_bn: string; value: string }[]; kycIdTypes: { value: string; label: string }[];
    can: { verify_kyc: boolean; waive_kyc: boolean; submit: boolean; decide: boolean };
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
async function submit(): Promise<void> {
    const pending = p.value.kyc_status === 'pending' ? ' KYC is not verified, so it will be referred.' : '';
    if (await confirmAction({ title: `Submit ${p.value.number}?`, body: `The underwriting rules decide whether it is approved now or referred to an underwriter.${pending}`, confirmLabel: 'Submit proposal' })) {
        router.post(`${base}/submit`, {}, { preserveScroll: true });
    }
}
const words = (v: string) => v.toLowerCase().replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const facts = computed(() => [
    { label: `Gross premium (${p.value.currency})`, value: formatMoney(p.value.gross_premium), num: true },
    { label: 'Sum insured', value: formatMoney(p.value.sum_insured), num: true },
    { label: 'Cover starts', value: formatDate(p.value.inception) },
    { label: 'Underwriting', value: p.value.underwriting_status ? words(p.value.underwriting_status) : 'Not submitted' },
]);
</script>

<template>
    <AppLayout help="policies" :title="proposal.number">
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
                            <p v-if="proposal.manual_loading" class="mt-2 text-ui">Special terms: loading {{ proposal.manual_loading }}% — {{ proposal.manual_loading_reason }}</p>
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
