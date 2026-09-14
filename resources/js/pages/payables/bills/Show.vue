<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { useMoneyForm } from '@/lib/moneyForm';

/** The supplier bill page (addendum v2 §B.4): lines with VAT and deductions, the payments that paid it, and approve / reject / cancel with the journal first. */
const props = defineProps<{
    bill: { id: string; number: string; status: string; supplier: { id: string; name: string; code: string }; reference: string; bill_date: string; due_date: string; accounting_date: string | null;
        branch: string; description: string | null; currency: string; net: string; vat: string; vds: string; tds: string; gross: string; payable: string; paid: string; outstanding: string;
        cancelled_reason: string | null; in_payment_run: boolean; approval_via_inbox: boolean };
    lines: { line_no: number; description: string; account: string; claim: { id: string; number: string } | null; policy: { id: string; number: string } | null; net: string; vat: string; vds: string; tds: string }[];
    payments: { id: string; number: string; pay_date: string; status: string; amount: string }[];
    actions: { submit: boolean; approve: boolean; reject: boolean; cancel: boolean };
    today: string;
    documentUpload: string | null;
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
    documents?: StoredDocumentRow[];
}>();

const base = `/payables/bills/${props.bill.id}`;
const facts = computed(() => [
    { label: `Payable (${props.bill.currency})`, value: formatMoney(props.bill.payable), num: true },
    { label: 'Outstanding', value: formatMoney(props.bill.outstanding), num: true },
    { label: 'Due', value: formatDate(props.bill.due_date) },
    { label: 'Invoice', value: props.bill.reference },
]);
const confirm = useJournalConfirm();
function approve(): void {
    void confirm.request(`${base}/approve`, {}, `Approve and post ${props.bill.number}?`, `Approve and post ${formatMoney(props.bill.gross)} ${props.bill.currency}`);
}
function submit(): void {
    router.post(`${base}/submit`, {}, { preserveScroll: true });
}
const drawer = ref<'reject' | 'cancel' | null>(null);
const done = () => (drawer.value = null);
const rejecting = useForm({ reason: '' });
const cancelling = useMoneyForm(() => `${base}/cancel`, { reason: '', on: '' }, done);
function openCancel(): void {
    cancelling.form.defaults({ reason: '', on: props.today });
    cancelling.form.reset();
    drawer.value = 'cancel';
}
const cell = 'border-b border-line px-3';
</script>

<template>
    <AppLayout help="bank" :title="bill.number">
        <ObjectPage
            :title="bill.number"
            :subtitle="`${bill.supplier.name} · invoice ${bill.reference}${bill.description ? ` · ${bill.description}` : ''}${bill.cancelled_reason ? ` · cancelled: ${bill.cancelled_reason}` : ''}`"
            :status="bill.status"
            :facts="facts"
            :crumbs="[{ label: 'Supplier bills', href: '/payables/bills' }]"
            :currency="bill.currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
            transactions-label="Payments"
        >
            <template #actions>
                <button v-if="actions.cancel" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="openCancel">Cancel bill</button>
                <button v-if="actions.reject" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'reject'">Return to draft</button>
                <button v-if="actions.submit" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="submit">Send for approval</button>
                <button v-if="actions.approve" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="approve">Approve and post</button>
            </template>
            <template #overview>
                <p v-if="bill.status === 'pending_approval' && !actions.approve" class="mb-4 max-w-[1100px] rounded-control border border-line bg-accent-soft px-3 py-2 text-ui" role="status">
                    Waiting for approval by someone other than the person who entered it{{ bill.approval_via_inbox ? ' (over an approval limit: decided from Approvals)' : '' }}.
                </p>
                <div class="max-w-[1100px] overflow-x-auto border border-line">
                    <table class="w-full border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2">
                            <tr class="h-(--row-h)"><th :class="cell" class="w-10 text-left font-medium">#</th><th :class="cell" class="text-left font-medium">What for</th><th :class="cell" class="text-left font-medium">Expense account</th><th :class="cell" class="text-left font-medium">Claim</th><th :class="cell" class="text-right font-medium">Net</th><th :class="cell" class="text-right font-medium">VAT</th><th :class="cell" class="text-right font-medium">VDS</th><th :class="cell" class="text-right font-medium">TDS</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in lines" :key="l.line_no" class="h-(--row-h)">
                                <td :class="cell" class="tabular-nums">{{ l.line_no }}</td><td :class="cell">{{ l.description }}</td><td :class="cell">{{ l.account }}</td>
                                <td :class="cell"><Link v-if="l.claim" :href="`/claims/${l.claim.id}`" class="text-accent-text hover:underline">{{ l.claim.number }}</Link><span v-else class="text-ink-2">—</span></td>
                                <td :class="cell" class="num">{{ formatMoney(l.net) }}</td><td :class="cell" class="num">{{ formatMoney(l.vat) }}</td><td :class="cell" class="num">{{ formatMoney(l.vds) }}</td><td :class="cell" class="num">{{ formatMoney(l.tds) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-6 grid max-w-[1100px] gap-6 lg:grid-cols-2">
                    <dl class="grid grid-cols-[1fr_auto] gap-y-1 rounded-control border border-line p-3 text-ui">
                        <dt class="text-ink-2">Net</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.net) }}</dd>
                        <dt class="text-ink-2">VAT</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.vat) }}</dd>
                        <dt class="text-ink-2">Gross</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.gross) }}</dd>
                        <dt class="text-ink-2">Less VAT deducted at source</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.vds) }}</dd>
                        <dt class="text-ink-2">Less tax deducted at source</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.tds) }}</dd>
                        <dt class="font-medium">Payable to {{ bill.supplier.name }}</dt><dd class="text-right font-medium tabular-nums">{{ formatMoney(bill.payable) }}</dd>
                        <dt class="text-ink-2">Paid</dt><dd class="text-right tabular-nums">{{ formatMoney(bill.paid) }}</dd>
                    </dl>
                    <dl class="grid grid-cols-[160px_1fr] content-start gap-y-1 text-ui">
                        <dt class="text-ink-2">Supplier</dt><dd><Link :href="`/payables/suppliers/${bill.supplier.id}`" class="text-accent-text hover:underline">{{ bill.supplier.name }}</Link> ({{ bill.supplier.code }})</dd>
                        <dt class="text-ink-2">Bill date</dt><dd>{{ formatDate(bill.bill_date) }}</dd>
                        <dt class="text-ink-2">Posted on</dt><dd>{{ bill.accounting_date ? formatDate(bill.accounting_date) : 'Not posted yet' }}</dd>
                        <dt class="text-ink-2">Branch</dt><dd>{{ bill.branch }}</dd>
                        <dt class="text-ink-2">In a payment run</dt><dd>{{ bill.in_payment_run ? 'Yes, waiting to be released' : 'No' }}</dd>
                    </dl>
                </div>
            </template>
            <template #transactions>
                <ul class="max-w-[800px] border border-line">
                    <li v-for="p in payments" :key="p.id" class="flex items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                        <Link :href="`/payables/payment-runs/${p.id}`" class="w-44 text-accent-text hover:underline">{{ p.number }}</Link>
                        <span class="w-32 tabular-nums font-medium">{{ formatMoney(p.amount) }}</span>
                        <StatusBadge :status="p.status" /><span class="text-ink-2">pay date {{ formatDate(p.pay_date) }}</span>
                    </li>
                    <li v-if="payments.length === 0" class="px-3 py-4 text-ui text-ink-2">Not in any payment run yet.</li>
                </ul>
            </template>
        </ObjectPage>

        <Drawer :open="drawer === 'reject'" :title="`Return ${bill.number} to draft`" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Return to draft" :dirty="rejecting.isDirty" :processing="rejecting.processing" :error="(rejecting.errors as Record<string, string>).form" @submit="rejecting.post(`${base}/reject`, { onSuccess: done })" @cancel="done">
                <Field id="reject_reason" label="What needs changing" :error="rejecting.errors.reason"><TextInput v-model="rejecting.reason" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'cancel'" :title="`Cancel ${bill.number}`" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and cancel" :dirty="cancelling.form.isDirty" :processing="cancelling.form.processing" :error="(cancelling.form.errors as Record<string, string>).form" @submit="cancelling.review" @cancel="done">
                <Field id="cancel_reason" label="Reason" :error="cancelling.form.errors.reason"><TextInput v-model="cancelling.form.reason" /></Field>
                <Field id="cancel_on" label="Date" hint="A posted bill is reversed on this date." :error="cancelling.form.errors.on"><DateInput v-model="cancelling.form.on" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="cancelling.previewOpen.value" :result="cancelling.preview.value" :title="`Cancel ${bill.number}?`" confirm-label="Confirm and cancel" :currency="bill.currency" :processing="cancelling.form.processing" @confirm="cancelling.post" />
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="bill.currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
