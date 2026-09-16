<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { useMoneyForm } from '@/lib/moneyForm';
import { type PreviewResult, previewJournal } from '@/lib/preview';
const currency = useEntityCurrency();

/** Design addendum v2 §B.6 float page (an object page like the other documents): vouchers with receipts, replenishments (request ✕ approve), cash counts. */
type Option = { id: string; label: string };
interface VoucherRow { id: string; number: string; date: string; payee: string; description: string; account: string; amount: string; replenishment: string | null; receipts: { name: string; url: string }[] }
interface ReplenishmentRow { id: string; number: string; amount: string; status: string; requested_at: string; paid_on: string | null; requested_by: string; decided_by: string | null; reason: string | null }
interface CountRow { date: string; counted: string; expected: string; difference: string; by: string; note: string | null }
const props = defineProps<{
    float: { id: string; code: string; name: string; branch: string; custodian: string; limit: string; on_hand: string; to_replenish: string; status: string };
    vouchers: VoucherRow[];
    replenishments: ReplenishmentRow[];
    counts: CountRow[];
    accounts: Option[];
    bankAccounts: Option[];
    can: { spend: boolean; replenish: boolean; approve: boolean; count: boolean };
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
}>();

const today = useBusinessToday();
const title = computed(() => `${props.float.code} · ${props.float.name}`);
const facts = computed(() => [
    { label: 'Float limit ({{ currency }})', value: formatMoney(props.float.limit), num: true },
    { label: 'Cash on hand', value: formatMoney(props.float.on_hand), num: true },
    { label: 'Spent, to replenish', value: formatMoney(props.float.to_replenish), num: true },
]);
const pending = computed(() => props.replenishments.filter((r) => r.status === 'pending_approval').length);

const spending = ref(false);
const replenishing = ref(false);
const counting = ref(false);
const voucher = useForm<{ voucher_date: string; payee: string; description: string; account_id: string; amount: string; receipt: File | null }>({ voucher_date: today, payee: '', description: '', account_id: '', amount: '', receipt: null });
const voucherPreview = ref<PreviewResult | null>(null);
const voucherPreviewOpen = ref(false);
async function reviewVoucher(): Promise<void> {
    voucher.clearErrors();
    const { receipt: _receipt, ...data } = voucher.data();
    const outcome = await previewJournal(`/petty-cash/${props.float.id}/vouchers`, data);
    if (!outcome.ok) {
        voucher.setError(outcome.errors as never);
        return;
    }
    voucherPreview.value = outcome.result;
    voucherPreviewOpen.value = true;
}
function postVoucher(): void {
    voucher.post(`/petty-cash/${props.float.id}/vouchers`, { forceFormData: true, preserveScroll: true, onSuccess: () => { voucher.reset(); spending.value = false; }, onFinish: () => (voucherPreviewOpen.value = false) });
}
const replenish = useForm({ bank_account_id: props.bankAccounts[0]?.id ?? '' });
const count = useMoneyForm(() => `/petty-cash/${props.float.id}/counts`, { counted_on: today, counted: '', note: '' }, () => (counting.value = false));
const confirm = useJournalConfirm();

// Deciding a replenishment: approve (pay date, then the journal preview) or reject (with a reason), each in a drawer.
const deciding = ref<{ row: ReplenishmentRow; decision: 'approve' | 'reject' } | null>(null);
const paidOn = ref(today);
const rejecting = useForm({ reason: '' });
function decide(row: ReplenishmentRow, decision: 'approve' | 'reject'): void {
    paidOn.value = today;
    rejecting.reset();
    rejecting.clearErrors();
    deciding.value = { row, decision };
}
function approve(): void {
    const row = deciding.value?.row;
    if (!row) return;
    deciding.value = null;
    void confirm.request(`/petty-cash/replenishments/${row.id}/approve`, { paid_on: paidOn.value }, `Approve ${row.number} and pay it from the bank?`, `Pay ${formatMoney(row.amount, currency)}`);
}
function reject(): void {
    const row = deciding.value?.row;
    if (!row) return;
    rejecting.post(`/petty-cash/replenishments/${row.id}/reject`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });
}

const voucherColumns: DataColumn<VoucherRow>[] = [
    { id: 'number', header: 'Voucher', value: (v) => v.number, width: 170 },
    { id: 'date', header: 'Date', type: 'date', value: (v) => v.date },
    { id: 'payee', header: 'Paid to', value: (v) => v.payee, width: 180 },
    { id: 'description', header: 'For', value: (v) => v.description, width: 220 },
    { id: 'account', header: 'Account', value: (v) => v.account, width: 200, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (v) => v.amount, total: true },
    { id: 'receipts', header: 'Receipt', value: (v) => v.receipts.map((d) => d.name).join(', '), width: 150 },
    { id: 'replenishment', header: 'Replenished by', value: (v) => v.replenishment ?? 'Not yet', width: 200, muted: true },
];
const replenishmentColumns: DataColumn<ReplenishmentRow>[] = [
    { id: 'number', header: 'Replenishment', value: (r) => r.number, width: 170 },
    { id: 'requested_at', header: 'Requested', type: 'date', value: (r) => r.requested_at },
    { id: 'requested_by', header: 'Requested by', value: (r) => r.requested_by, width: 160, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status },
    { id: 'decision', header: 'Decision', value: (r) => (r.decided_by ? `${r.decided_by}${r.paid_on ? `, paid ${formatDate(r.paid_on)}` : ''}${r.reason ? `: ${r.reason}` : ''}` : ''), width: 280 },
];
const countColumns: DataColumn<CountRow>[] = [
    { id: 'date', header: 'Counted on', type: 'date', value: (c) => c.date },
    { id: 'counted', header: 'Counted', type: 'money', value: (c) => c.counted },
    { id: 'expected', header: 'Book balance', type: 'money', value: (c) => c.expected },
    { id: 'difference', header: 'Difference', type: 'money', value: (c) => c.difference },
    { id: 'by', header: 'Counted by', value: (c) => c.by, width: 160, muted: true },
    { id: 'note', header: 'Note', value: (c) => c.note, width: 240, muted: true },
];
</script>

<template>
    <AppLayout help="pettycash" :title="`${float.code} petty cash`">
        <ObjectPage
            :title="title"
            :subtitle="`${float.branch} · held by ${float.custodian}`"
            :status="float.status"
            :facts="facts"
            :crumbs="[{ label: 'Petty cash', href: '/petty-cash' }]"
            :currency="currency"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :hidden-tabs="['documents']"
            :extra-tabs="[{ value: 'replenishments', label: 'Replenishments' }, { value: 'counts', label: 'Cash counts' }]"
        >
            <template #actions>
                <Link :href="`/petty-cash/book?float=${float.id}`" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-ink-2 hover:bg-surface-2 hover:text-ink">Petty cash book</Link>
                <button v-if="can.count" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="counting = true">Count the cash</button>
                <button v-if="can.replenish && float.to_replenish !== '0.00'" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="replenishing = true">Request replenishment</button>
                <button v-if="can.spend" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="spending = true">Pay a voucher</button>
            </template>
            <template #overview>
                <p v-if="pending && can.approve" class="mb-4 max-w-[1100px] rounded-control border border-line bg-accent-soft px-3 py-2 text-ui" role="status">
                    {{ pending }} replenishment{{ pending === 1 ? ' is' : 's are' }} waiting for your decision on the Replenishments tab.
                </p>
                <h2 class="mb-2 text-ui font-medium">Vouchers</h2>
                <div class="border border-line">
                    <DataTable id="petty-cash-vouchers" label="Vouchers" :columns="voucherColumns" :rows="vouchers" :row-key="(v) => v.id" :currency="currency" :url-sync="false" :open-on-click="false" compact-toolbar
                        empty-text="No vouchers paid from this float yet.">
                        <template #cell-receipts="{ row }">
                            <a v-for="d in row.receipts" :key="d.url" :href="d.url" class="mr-2 text-accent-text hover:underline">{{ d.name }}</a>
                        </template>
                    </DataTable>
                </div>
            </template>
            <template #tab-replenishments>
                <div class="max-w-[1100px] border border-line">
                    <DataTable id="petty-cash-replenishments" label="Replenishments" :columns="replenishmentColumns" :rows="replenishments" :row-key="(r) => r.id" :currency="currency" :url-sync="false" :open-on-click="false"
                        compact-toolbar empty-text="No replenishment requested yet.">
                        <template #cell-decision="{ row }">
                            <div v-if="can.approve && row.status === 'pending_approval'" class="flex items-center gap-2">
                                <button type="button" class="h-7 rounded-control bg-accent px-2 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="decide(row, 'approve')">Approve</button>
                                <button type="button" class="h-7 rounded-control border border-line-control px-2 text-ui hover:bg-surface-2" @click="decide(row, 'reject')">Reject</button>
                            </div>
                            <span v-else class="text-ink-2">{{ row.decided_by ? `${row.decided_by}${row.paid_on ? `, paid ${formatDate(row.paid_on)}` : ''}${row.reason ? `: ${row.reason}` : ''}` : '' }}</span>
                        </template>
                    </DataTable>
                </div>
            </template>
            <template #tab-counts>
                <div class="max-w-[1100px] border border-line">
                    <DataTable id="petty-cash-counts" label="Cash counts" :columns="countColumns" :rows="counts" :row-key="(c) => `${c.date}-${c.counted}-${c.by}`" :currency="currency" :url-sync="false" :open-on-click="false"
                        compact-toolbar empty-text="The cash has not been counted yet." />
                </div>
            </template>
        </ObjectPage>

        <Drawer v-model:open="spending" title="Pay a voucher">
            <FormLayout submit-label="Review the journal" :dirty="voucher.isDirty" :processing="voucher.processing" :error="(voucher.errors as Record<string, string>).form" @submit="reviewVoucher" @cancel="spending = false">
                <p class="text-ui text-ink-2">Cash on hand {{ formatMoney(float.on_hand) }} {{ currency }}.</p>
                <Field id="voucher_date" label="Date" :error="voucher.errors.voucher_date"><DateInput v-model="voucher.voucher_date" /></Field>
                <Field id="payee" label="Paid to" :error="voucher.errors.payee"><TextInput v-model="voucher.payee" /></Field>
                <Field id="description" label="For" :error="voucher.errors.description"><TextInput v-model="voucher.description" /></Field>
                <Field id="account_id" label="Expense account" :error="voucher.errors.account_id"><SelectInput id="account_id" v-model="voucher.account_id" placeholder="Choose an account" :options="accounts.map((a) => ({ value: a.id, label: a.label }))" /></Field>
                <Field id="amount" label="Amount ({{ currency }})" :error="voucher.errors.amount"><MoneyInput v-model="voucher.amount" /></Field>
                <Field id="receipt" label="Receipt photo" optional :error="voucher.errors.receipt">
                    <input id="receipt" type="file" accept="image/*,application/pdf" capture="environment" class="text-ui" @change="(e) => (voucher.receipt = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                </Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="replenishing" title="Request replenishment">
            <FormLayout submit-label="Request replenishment" :dirty="true" :processing="replenish.processing" :error="(replenish.errors as Record<string, string>).form" @submit="replenish.post(`/petty-cash/${float.id}/replenishments`, { onSuccess: () => (replenishing = false) })" @cancel="replenishing = false">
                <p class="text-ui text-ink-2">Tops the float back up by the {{ formatMoney(float.to_replenish) }} {{ currency }} of vouchers paid since the last replenishment. The finance manager approves it.</p>
                <Field id="bank_account_id" label="Pay from" :error="replenish.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="replenish.bank_account_id" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="counting" title="Count the cash">
            <FormLayout submit-label="Review" :dirty="count.form.isDirty" :processing="count.form.processing" :error="(count.form.errors as Record<string, string>).form" @submit="count.review" @cancel="counting = false">
                <p class="text-ui text-ink-2">The book says {{ formatMoney(float.on_hand) }} {{ currency }}. A difference is posted as a cash shortage or surplus.</p>
                <Field id="counted_on" label="Counted on" :error="count.form.errors.counted_on"><DateInput v-model="count.form.counted_on" /></Field>
                <Field id="counted" label="Cash counted ({{ currency }})" :error="count.form.errors.counted"><MoneyInput v-model="count.form.counted" /></Field>
                <Field id="note" label="Note" optional :error="count.form.errors.note"><TextInput v-model="count.form.note" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="deciding?.decision === 'approve'" :title="`Approve ${deciding?.row.number ?? ''}`" @update:open="(o) => !o && (deciding = null)">
            <FormLayout submit-label="Review the journal" :dirty="true" :processing="confirm.state.processing" @submit="approve" @cancel="deciding = null">
                <p class="text-ui text-ink-2">Pays {{ formatMoney(deciding?.row.amount ?? '0') }} {{ currency }} from the bank into the float.</p>
                <Field id="paid_on" label="Paid on"><DateInput v-model="paidOn" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="deciding?.decision === 'reject'" :title="`Reject ${deciding?.row.number ?? ''}`" @update:open="(o) => !o && (deciding = null)">
            <FormLayout submit-label="Reject" :dirty="rejecting.isDirty" :processing="rejecting.processing" :error="(rejecting.errors as Record<string, string>).form" @submit="reject" @cancel="deciding = null">
                <Field id="reject_reason" label="Reason" :error="rejecting.errors.reason"><TextInput v-model="rejecting.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="voucherPreviewOpen" :result="voucherPreview" title="Pay this voucher?" confirm-label="Pay" :currency="currency" :processing="voucher.processing" @confirm="postVoucher" />
        <JournalPreviewDialog v-model:open="count.previewOpen.value" :result="count.preview.value" title="Record this count?" confirm-label="Record the count" :currency="currency" :processing="count.form.processing" @confirm="count.post" />
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" :currency="currency" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
