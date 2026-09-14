<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ClaimPolicyPanel, { type ClaimPolicyFacts } from '@/components/claims/ClaimPolicyPanel.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, DocumentGeneration, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';

const props = defineProps<{
    claim: { id: string; number: string; status: string; loss_date: string; reported_on: string; description: string; reserve: string; uncommitted: string; currency: string; status_reason: string | null; policy: { id: string; number: string | null; policyholder: string; policyholder_id: string } };
    reserves: { version: number; reserve: string; delta: string; kind: string; reason: string; recorded_on: string }[];
    payments: { id: string; amount: string; status: string; approved_on: string; paid_on: string | null; bank_account_id: string | null; pay_from: string; can_request_release: boolean; can_release: boolean }[];
    recoveries: { type: string; amount: string; received_on: string; reference: string | null; number?: string | null; payer?: string | null }[];
    /** Gap fix GA-21: the bank accounts a recovery is paid into, the main one first. */
    recoveryBankAccounts?: { id: string; label: string }[];
    actions: { reserve: boolean; approve: boolean; recover: boolean; close: boolean; reject: boolean; reopen: boolean };
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
    documents?: StoredDocumentRow[];
    documentUpload: string | null;
    /** Gap audit GA-41: print the claim acknowledgement and each payment's discharge voucher. */
    documentGeneration?: DocumentGeneration;
    today: string;
    /** GA-12: the policy as the claims desk checks it (read-only). */
    policyFacts?: ClaimPolicyFacts | null;
    /** Flow fix X3: set right after a reserve when this user may approve the settlement now. */
    nextStep?: 'approve_payment' | null;
}>();

type DrawerName = 'reserve' | 'payment' | 'recover' | 'close' | 'reject' | 'reopen' | 'release';
const base = `/claims/${props.claim.id}`;
const drawer = ref<DrawerName | null>(null);
const releasing = ref<string | null>(null);
const done = () => (drawer.value = null);
const reserve = useMoneyForm(() => `${base}/reserve`, { reserve: '', reason: '', on: '' }, done);
const payment = useMoneyForm(() => `${base}/payments`, { amount: '', payee_party_id: '', on: '' }, done);
const recover = useMoneyForm(() => `${base}/recover`, { type: 'salvage', amount: '', received_on: '', reference: '', bank_account_id: '', payer_party_id: '' }, done);
const recoveryPayer = ref<LookupResult | null>(null); // gap fix GA-21
const recoveryOpened = ref(0);
const closing = useMoneyForm(() => `${base}/close`, { reason: '', on: '' }, done);
const release = useMoneyForm(() => `/claim-payments/${releasing.value}/release`, { paid_on: '' }, done);
// G1: the bank account is fixed when the release is requested; the drawer only says which one pays.
const releasePayFrom = computed(() => props.payments.find((p) => p.id === releasing.value)?.pay_from ?? '');
// Gap fix GA-09: rejecting releases the reserve and reopening reserves again, so both show their journal before posting, like the other money actions.
const rejecting = useMoneyForm(() => `${base}/reject`, { reason: '', on: '' }, done);
const reopening = useMoneyForm(() => `${base}/reopen`, { reason: '', on: '' }, done);
const decision = computed(() => (drawer.value === 'reopen' ? reopening : rejecting));
const money = computed(() => ({ reserve, payment, recover, close: closing, release, reject: rejecting, reopen: reopening })[drawer.value as 'reserve'] ?? null);
const words = (v: string) => v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const paid = computed(() => props.payments.filter((p) => p.status === 'paid').length);
const facts = computed(() => [
    { label: `Case reserve (${props.claim.currency})`, value: formatMoney(props.claim.reserve), num: true },
    { label: 'Payments', value: `${props.payments.length} (${paid.value} paid)` },
    { label: 'Date of loss', value: formatDate(props.claim.loss_date) },
    { label: 'Reported', value: formatDate(props.claim.reported_on) },
]);
const previewTitle = computed(() => ({ reserve: 'Post the new reserve?', payment: 'Approve this payment?', recover: 'Post the recovery?', close: `Close ${props.claim.number}?`, release: 'Pay the claim?', reject: `Reject ${props.claim.number}?`, reopen: `Reopen ${props.claim.number}?` })[drawer.value as 'reserve'] ?? '');

// Flow fix X3: each drawer starts from what the claim already knows — the reserve left, the policyholder, today; the release drawer shows the bank the release was requested from.
const nextDismissed = ref(false);
const offerApproval = computed(() => props.nextStep === 'approve_payment' && props.actions.approve && !nextDismissed.value);
// Flow fix X8: the payee is looked up (or created inline as a vendor or beneficiary); it starts as the policyholder.
const payee = ref<LookupResult | null>(null);
const paymentOpened = ref(0);
function openPayment(): void {
    nextDismissed.value = true;
    payee.value = props.claim.policy.policyholder_id ? { id: props.claim.policy.policyholder_id, label: props.claim.policy.policyholder } : null;
    paymentOpened.value++;
    payment.form.defaults({ amount: props.claim.uncommitted, payee_party_id: props.claim.policy.policyholder_id, on: props.today });
    payment.form.reset();
    drawer.value = 'payment';
}
// Flow fix X2: events recorded as they happen start at today.
function openDrawer(name: 'reserve' | 'recover' | 'reject' | 'reopen'): void {
    if (name === 'reserve') {
        reserve.form.defaults({ reserve: '', reason: '', on: props.today });
        reserve.form.reset();
    } else if (name === 'recover') {
        // Gap fix GA-21: a recovery receipt names the bank account it was paid into (the main one first) and its payer.
        recover.form.defaults({ type: 'salvage', amount: '', received_on: props.today, reference: '', bank_account_id: props.recoveryBankAccounts?.[0]?.id ?? '', payer_party_id: '' });
        recover.form.reset();
        recoveryPayer.value = null;
        recoveryOpened.value++;
    } else {
        const form = (name === 'reopen' ? reopening : rejecting).form;
        form.defaults({ reason: '', on: props.today });
        form.reset();
    }
    drawer.value = name;
}
function openClose(): void {
    closing.form.defaults({ reason: '', on: props.today });
    closing.form.reset();
    drawer.value = 'close';
}

function requestRelease(id: string): void {
    router.post(`/claim-payments/${id}/request-release`, {}, { preserveScroll: true });
}
function openRelease(id: string): void {
    releasing.value = id;
    release.form.defaults({ paid_on: props.today });
    release.form.reset();
    drawer.value = 'release';
}
</script>

<template>
    <AppLayout help="claims" :title="claim.number">
        <div v-if="offerApproval" class="mb-3 flex flex-wrap items-center gap-3 rounded-control border border-line bg-accent-soft px-3 py-2 text-ui" role="status">
            <span>Reserve set. Next, approve the settlement: {{ formatMoney(claim.uncommitted) }} {{ claim.currency }} of reserve is left.</span>
            <span class="ml-auto flex gap-2">
                <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="nextDismissed = true">Not now</button>
                <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="openPayment">Approve payment</button>
            </span>
        </div>
        <ObjectPage
            :title="claim.number"
            :subtitle="`${claim.description} · policy ${claim.policy.number} · ${claim.policy.policyholder}${claim.status_reason ? ` · ${claim.status_reason}` : ''}`"
            :status="claim.status"
            :facts="facts"
            :crumbs="[{ label: 'Claims', href: '/claims' }]"
            :currency="claim.currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
            :document-generation="documentGeneration"
            transactions-label="Reserves and payments"
        >
            <template #actions>
                <button v-if="actions.recover" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="openDrawer('recover')">Record recovery</button>
                <button v-if="actions.reopen" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="openDrawer('reopen')">Reopen</button>
                <button v-if="actions.reject" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="openDrawer('reject')">Reject</button>
                <button v-if="actions.close" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="openClose">Close claim</button>
                <button v-if="actions.approve" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="openPayment">Approve payment</button>
                <button v-if="actions.reserve" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="openDrawer('reserve')">Set reserve</button>
            </template>
            <template #overview>
                <ClaimPolicyPanel v-if="policyFacts" :facts="policyFacts" :currency="claim.currency" class="mb-6 max-w-[1100px]" />
                <div class="grid max-w-[1100px] gap-6 lg:grid-cols-2">
                    <section>
                        <h2 class="mb-2 text-ui font-medium">Payments</h2>
                        <ul class="border border-line">
                            <li v-for="p in payments" :key="p.id" class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                <span class="w-32 tabular-nums font-medium">{{ formatMoney(p.amount) }}</span>
                                <StatusBadge :status="p.status" />
                                <span class="text-ink-2">approved {{ formatDate(p.approved_on) }}<template v-if="p.paid_on">, paid {{ formatDate(p.paid_on) }}</template></span>
                                <span class="ml-auto flex gap-2">
                                    <button v-if="p.can_request_release" type="button" class="h-7 rounded-control border border-line-control px-2 hover:bg-surface-2" @click="requestRelease(p.id)">Request release</button>
                                    <button v-if="p.can_release" type="button" class="h-7 rounded-control bg-accent px-2 font-medium text-accent-ink hover:bg-accent-hover" @click="openRelease(p.id)">Pay</button>
                                </span>
                            </li>
                            <li v-if="payments.length === 0" class="px-3 py-4 text-ui text-ink-2">No payments yet.</li>
                        </ul>
                    </section>
                    <section>
                        <h2 class="mb-2 text-ui font-medium">Recoveries</h2>
                        <ul class="border border-line">
                            <li v-for="(r, index) in recoveries" :key="index" class="flex items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                <span class="w-32 tabular-nums font-medium">{{ formatMoney(r.amount) }}</span><span>{{ words(r.type) }}</span><span class="text-ink-2">{{ formatDate(r.received_on) }}</span><span v-if="r.payer" class="text-ink-2">from {{ r.payer }}</span><span class="ml-auto text-ink-2">{{ [r.number, r.reference].filter(Boolean).join(' · ') }}</span>
                            </li>
                            <li v-if="recoveries.length === 0" class="px-3 py-4 text-ui text-ink-2">No recoveries.</li>
                        </ul>
                    </section>
                </div>
            </template>
            <template #transactions>
                <h2 class="mb-2 text-ui font-medium">Reserve history</h2>
                <div class="max-w-[900px] overflow-x-auto border border-line">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="w-12 border-b border-line px-3 text-left font-medium">Version</th><th class="w-32 border-b border-line px-3 text-left font-medium">Recorded</th><th class="w-36 border-b border-line px-3 text-right font-medium">Reserve ({{ claim.currency }})</th><th class="w-36 border-b border-line px-3 text-right font-medium">Change</th><th class="w-32 border-b border-line px-3 text-left font-medium">Kind</th><th class="border-b border-line px-3 text-left font-medium">Reason</th></tr></thead>
                        <tbody><tr v-for="r in reserves" :key="r.version" class="h-(--row-h)"><td class="border-b border-line px-3 tabular-nums">{{ r.version }}</td><td class="border-b border-line px-3">{{ formatDate(r.recorded_on) }}</td><td class="num border-b border-line px-3">{{ formatMoney(r.reserve) }}</td><td class="num border-b border-line px-3">{{ formatMoney(r.delta) }}</td><td class="border-b border-line px-3">{{ words(r.kind) }}</td><td class="truncate border-b border-line px-3 text-ink-2">{{ r.reason }}</td></tr></tbody>
                    </table>
                </div>
            </template>
        </ObjectPage>

        <Drawer :open="drawer === 'reserve'" title="Set the case reserve" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and post" :dirty="reserve.form.isDirty" :processing="reserve.form.processing" :error="(reserve.form.errors as Record<string, string>).form" @submit="reserve.review" @cancel="done">
                <Field id="reserve" :label="`New total reserve (${claim.currency})`" :hint="`Currently ${formatMoney(claim.reserve)}. The change posts as an increase or release.`" :error="reserve.form.errors.reserve"><MoneyInput v-model="reserve.form.reserve" /></Field>
                <Field id="reserve_reason" label="Reason" :error="reserve.form.errors.reason"><TextInput v-model="reserve.form.reason" /></Field>
                <Field id="reserve_on" label="Date" :error="reserve.form.errors.on"><DateInput v-model="reserve.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'payment'" title="Approve a payment" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and approve" :dirty="payment.form.isDirty" :processing="payment.form.processing" :error="(payment.form.errors as Record<string, string>).form" @submit="payment.review" @cancel="done">
                <Field id="payment_amount" :label="`Amount (${claim.currency})`" :error="payment.form.errors.amount"><MoneyInput v-model="payment.form.amount" /></Field>
                <Field id="payee_party_id" label="Payee" hint="A name or tax ID. Ctrl+N adds a garage, surveyor or beneficiary." :error="payment.form.errors.payee_party_id">
                    <LookupInput id="payee_party_id" :key="paymentOpened" v-model="payment.form.payee_party_id" type="payee" creatable :create-context="{ claim_id: claim.id }" :initial="payee" placeholder="Type a name" @selected="payee = $event" />
                </Field>
                <Field id="payment_on" label="Approval date" :error="payment.form.errors.on"><DateInput v-model="payment.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'recover'" title="Record a recovery" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and post" :dirty="recover.form.isDirty" :processing="recover.form.processing" :error="(recover.form.errors as Record<string, string>).form" @submit="recover.review" @cancel="done">
                <Field id="recovery_type" label="Type" :error="recover.form.errors.type"><SelectInput id="recovery_type" v-model="recover.form.type" :options="['salvage', 'subrogation', 'third_party'].map((t) => ({ value: t, label: words(t) }))" /></Field>
                <Field id="recovery_amount" :label="`Amount (${claim.currency})`" :error="recover.form.errors.amount"><MoneyInput v-model="recover.form.amount" /></Field>
                <Field id="received_on" label="Received on" :error="recover.form.errors.received_on"><DateInput v-model="recover.form.received_on" /></Field>
                <Field id="recovery_payer" label="Paid by" hint="The salvage buyer, the third party or their insurer." :error="recover.form.errors.payer_party_id">
                    <LookupInput id="recovery_payer" :key="recoveryOpened" v-model="recover.form.payer_party_id" type="payer" :initial="recoveryPayer" placeholder="Type a name" @selected="recoveryPayer = $event" />
                </Field>
                <Field id="recovery_bank" label="Paid into" :error="recover.form.errors.bank_account_id">
                    <SelectInput id="recovery_bank" v-model="recover.form.bank_account_id" placeholder="Choose a bank account" :options="(recoveryBankAccounts ?? []).map((b) => ({ value: b.id, label: b.label }))" />
                </Field>
                <Field id="recovery_reference" label="Reference" optional :error="recover.form.errors.reference"><TextInput v-model="recover.form.reference" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'release'" title="Pay the claim" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and pay" :dirty="release.form.isDirty" :processing="release.form.processing" :error="(release.form.errors as Record<string, string>).form" @submit="release.review" @cancel="done">
                <Field id="paid_on" label="Paid on" hint="The person who requested the release cannot pay it." :error="release.form.errors.paid_on"><DateInput v-model="release.form.paid_on" /></Field>
                <div class="grid gap-1">
                    <span class="text-ui font-medium text-ink">Pay from</span>
                    <p id="release_bank" class="text-ui text-ink" aria-describedby="release_bank-hint">{{ releasePayFrom }}</p>
                    <p id="release_bank-hint" class="text-dense text-ink-2">Set when the release was requested.</p>
                </div>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'close'" :title="`Close ${claim.number}`" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and close" :dirty="closing.form.isDirty" :processing="closing.form.processing" :error="(closing.form.errors as Record<string, string>).form" @submit="closing.review" @cancel="done">
                <Field id="close_reason" label="Reason" :error="closing.form.errors.reason"><TextInput v-model="closing.form.reason" /></Field>
                <Field id="close_on" label="Date" hint="Any reserve left is released." :error="closing.form.errors.on"><DateInput v-model="closing.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'reject' || drawer === 'reopen'" :title="drawer === 'reject' ? `Reject ${claim.number}` : `Reopen ${claim.number}`" @update:open="(o) => !o && done()">
            <FormLayout :submit-label="drawer === 'reject' ? 'Review and reject' : 'Review and reopen'" :dirty="decision.form.isDirty" :processing="decision.form.processing" :error="(decision.form.errors as Record<string, string>).form" @submit="decision.review" @cancel="done">
                <Field id="decision_reason" label="Reason" :error="decision.form.errors.reason"><TextInput v-model="decision.form.reason" /></Field>
                <Field id="decision_on" label="Date" :error="decision.form.errors.on"><DateInput v-model="decision.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-if="money" v-model:open="money.previewOpen.value" :result="money.preview.value" :title="previewTitle" confirm-label="Confirm and post" :currency="claim.currency" :processing="money.form.processing" @confirm="money.post" />
    </AppLayout>
</template>
