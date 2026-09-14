<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';

/** Payment run workbench (slice 2.4): the bills and bank accounts it pays; submit, approve, release with the journal first, download the bank file. */
const props = defineProps<{
    run: { id: string; number: string; status: string; pay_date: string; currency: string; total: string; bills: number; bank: string; prepared_by: string | null; approved_by: string | null;
        released_by: string | null; cancelled_reason: string | null; files: { version: number; sha256: string; generated_at: string }[] };
    items: { id: string; supplier: string; bill_id: string; bill: string; reference: string; due_date: string; amount: string; status: string; bank: string; routing_no: string | null; account: string | null }[];
    actions: { submit: boolean; approve: boolean; reject: boolean; release: boolean; cancel: boolean; bank_file: boolean };
    waitingFor: string | null;
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
}>();

const base = `/payables/payment-runs/${props.run.id}`;
const facts = computed(() => [
    { label: `Total (${props.run.currency})`, value: formatMoney(props.run.total), num: true },
    { label: 'Bills', value: String(props.run.bills) },
    { label: 'Pay date', value: formatDate(props.run.pay_date) },
    { label: 'Paid from', value: props.run.bank },
]);
const confirm = useJournalConfirm();
function release(): void {
    void confirm.request(`${base}/release`, {}, `Release ${props.run.number}?`, `Release ${formatMoney(props.run.total)} ${props.run.currency} to the bank`);
}
function post(action: 'submit' | 'approve'): void {
    router.post(`${base}/${action}`, {}, { preserveScroll: true });
}
const drawer = ref<'reject' | 'cancel' | null>(null);
const done = () => (drawer.value = null);
const reasonForm = useForm({ reason: '' });
function sendReason(): void {
    reasonForm.post(`${base}/${drawer.value}`, { onSuccess: () => { reasonForm.reset(); done(); } });
}
type ItemRow = (typeof props.items)[number];
const itemColumns: DataColumn<ItemRow>[] = [
    { id: 'supplier', header: 'Supplier', value: (i) => i.supplier, width: 180 },
    { id: 'bill', header: 'Bill', value: (i) => i.bill, href: (i) => `/payables/bills/${i.bill_id}`, width: 170 },
    { id: 'reference', header: 'Invoice', value: (i) => i.reference, width: 130, muted: true },
    { id: 'due_date', header: 'Due', type: 'date', value: (i) => i.due_date },
    { id: 'bank', header: 'Bank', value: (i) => i.bank, width: 160 },
    { id: 'routing_no', header: 'Routing', value: (i) => i.routing_no ?? '—', width: 100, muted: true },
    { id: 'account', header: 'Account', value: (i) => i.account ?? '—', width: 110, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (i) => i.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (i) => i.status },
];
</script>

<template>
    <AppLayout help="payables" :title="run.number">
        <ObjectPage
            :title="run.number"
            :subtitle="[run.prepared_by ? `prepared by ${run.prepared_by}` : '', run.approved_by ? `approved by ${run.approved_by}` : '', run.released_by ? `released by ${run.released_by}` : '', run.cancelled_reason ? `cancelled: ${run.cancelled_reason}` : ''].filter(Boolean).join(' · ')"
            :status="run.status"
            :facts="facts"
            :crumbs="[{ label: 'Payment runs', href: '/payables/payment-runs' }]"
            :currency="run.currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :hidden-tabs="['documents']"
        >
            <template #actions>
                <button v-if="actions.cancel" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="drawer = 'cancel'">Cancel run</button>
                <button v-if="actions.reject" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'reject'">Return to draft</button>
                <a v-if="actions.bank_file" :href="`${base}/bank-file`" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" download>Download bank file (CSV)</a>
                <button v-if="actions.submit" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="post('submit')">Send for approval</button>
                <button v-if="actions.approve" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="post('approve')">Approve run</button>
                <button v-if="actions.release" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="release">Release to bank</button>
            </template>
            <template #overview>
                <p v-if="waitingFor" class="mb-4 max-w-[1100px] rounded-control border border-line bg-accent-soft px-3 py-2 text-ui" role="status">{{ waitingFor }}</p>
                <h2 class="mb-2 text-ui font-medium">Bills paid</h2>
                <div class="border border-line">
                    <DataTable id="payment-run-items" label="Bills paid" :columns="itemColumns" :rows="items" :row-key="(i) => i.id" :currency="run.currency" :url-sync="false" compact-toolbar
                        empty-text="The run has no bills." />
                </div>
                <section v-if="run.files.length > 0" class="mt-6 max-w-[700px]">
                    <h2 class="mb-2 text-ui font-medium">Bank files downloaded</h2>
                    <ul class="border border-line">
                        <li v-for="f in run.files" :key="f.version" class="flex gap-4 border-b border-line px-3 py-2 text-ui last:border-b-0"><span>Version {{ f.version }}</span><span class="text-ink-2">{{ formatDateTime(f.generated_at) }}</span><span class="ml-auto num text-dense text-ink-2">SHA-256 {{ f.sha256 }}…</span></li>
                    </ul>
                </section>
            </template>
        </ObjectPage>
        <Drawer :open="drawer !== null" :title="drawer === 'cancel' ? `Cancel ${run.number}` : `Return ${run.number} to draft`" @update:open="(o) => !o && done()">
            <FormLayout :submit-label="drawer === 'cancel' ? 'Cancel the run' : 'Return to draft'" :dirty="reasonForm.isDirty" :processing="reasonForm.processing" :error="(reasonForm.errors as Record<string, string>).form" @submit="sendReason" @cancel="done">
                <Field id="run_reason" label="Reason" :error="reasonForm.errors.reason"><TextInput v-model="reasonForm.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="run.currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
