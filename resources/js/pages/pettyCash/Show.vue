<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney } from '@/lib/format';
import { useJournalConfirm } from '@/lib/journalConfirm';
import { useMoneyForm } from '@/lib/moneyForm';
import { type PreviewResult, previewJournal } from '@/lib/preview';

/** Design addendum v2 §B.6 float page: pay a voucher (with the receipt photo), ask for replenishment, approve it, count the cash. */
type Option = { id: string; label: string };
const props = defineProps<{
    float: { id: string; code: string; name: string; branch: string; custodian: string; limit: string; on_hand: string; to_replenish: string; status: string };
    vouchers: { id: string; number: string; date: string; payee: string; description: string; account: string; amount: string; replenishment: string | null; receipts: { name: string; url: string }[] }[];
    replenishments: { id: string; number: string; amount: string; status: string; requested_at: string; paid_on: string | null; requested_by: string; decided_by: string | null; reason: string | null }[];
    counts: { date: string; counted: string; expected: string; difference: string; by: string; note: string | null }[];
    accounts: Option[];
    bankAccounts: Option[];
    can: { spend: boolean; replenish: boolean; approve: boolean; count: boolean };
}>();

const today = useBusinessToday();
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
const paidOn = ref(today);
const rejectReason = ref('');
function approve(id: string, number: string, amount: string): void {
    void confirm.request(`/petty-cash/replenishments/${id}/approve`, { paid_on: paidOn.value }, `Approve ${number} and pay it from the bank?`, `Pay ${formatMoney(amount)} BDT`);
}
</script>

<template>
    <AppLayout :title="`${float.code} petty cash`">
        <div class="grid max-w-[1100px] gap-5">
            <div><Breadcrumb :base="[{ label: 'Petty cash', href: '/petty-cash' }]" /></div>
            <header class="flex flex-wrap items-end gap-x-8 gap-y-3">
                <div>
                    <div class="flex items-center gap-3"><h1 class="text-title font-semibold">{{ float.code }} · {{ float.name }}</h1><StatusBadge :status="float.status" /></div>
                    <p class="text-ui text-ink-2">{{ float.branch }} · held by {{ float.custodian }}</p>
                </div>
                <dl class="flex flex-wrap gap-x-8">
                    <div><dt class="text-dense text-ink-2">Float limit (BDT)</dt><dd class="num text-left text-ui font-medium">{{ formatMoney(float.limit) }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Cash on hand</dt><dd class="num text-left text-ui font-medium">{{ formatMoney(float.on_hand) }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Spent, to replenish</dt><dd class="num text-left text-ui font-medium">{{ formatMoney(float.to_replenish) }}</dd></div>
                </dl>
                <div class="ml-auto flex flex-wrap gap-2">
                    <button v-if="can.spend" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="spending = true">Pay a voucher</button>
                    <button v-if="can.replenish && float.to_replenish !== '0.00'" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="replenishing = true">Request replenishment</button>
                    <button v-if="can.count" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="counting = true">Count the cash</button>
                    <Link :href="`/petty-cash/book?float=${float.id}`" class="inline-flex h-8 items-center text-ui text-accent-text hover:underline">Petty cash book</Link>
                </div>
            </header>

            <section v-if="replenishments.length">
                <h2 class="mb-2 text-ui font-medium">Replenishments</h2>
                <div class="overflow-x-auto rounded-panel border border-line">
                    <table class="w-full border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">Number</th><th class="border-b border-line px-3 text-left font-medium">Requested</th><th class="border-b border-line px-3 text-right font-medium">Amount (BDT)</th><th class="border-b border-line px-3 text-left font-medium">Status</th><th class="border-b border-line px-3 text-left font-medium">Decision</th><th class="border-b border-line px-3" /></tr></thead>
                        <tbody>
                            <tr v-for="r in replenishments" :key="r.id" class="h-(--row-h)">
                                <td class="border-b border-line px-3">{{ r.number }}</td><td class="border-b border-line px-3">{{ formatDate(r.requested_at) }} · {{ r.requested_by }}</td>
                                <td class="num border-b border-line px-3">{{ formatMoney(r.amount) }}</td><td class="border-b border-line px-3"><StatusBadge :status="r.status" /></td>
                                <td class="border-b border-line px-3 text-ink-2">{{ r.decided_by ? `${r.decided_by}${r.paid_on ? `, paid ${formatDate(r.paid_on)}` : ''}${r.reason ? `: ${r.reason}` : ''}` : '' }}</td>
                                <td class="border-b border-line px-3">
                                    <div v-if="can.approve && r.status === 'pending_approval'" class="flex items-center justify-end gap-2">
                                        <DateInput v-model="paidOn" class="w-32" aria-label="Paid on" />
                                        <button type="button" class="h-7 rounded-control bg-accent px-2 text-accent-ink" @click="approve(r.id, r.number, r.amount)">Approve</button>
                                        <input v-model="rejectReason" class="h-7 w-32 rounded-control border border-line-control px-2" placeholder="Reason to reject" />
                                        <button type="button" class="h-7 rounded-control border border-line-control px-2 disabled:opacity-50" :disabled="!rejectReason.trim()" @click="router.post(`/petty-cash/replenishments/${r.id}/reject`, { reason: rejectReason })">Reject</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-2 text-ui font-medium">Vouchers</h2>
                <div class="overflow-x-auto rounded-panel border border-line">
                    <table class="w-full border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">Voucher</th><th class="border-b border-line px-3 text-left font-medium">Date</th><th class="border-b border-line px-3 text-left font-medium">Paid to</th><th class="border-b border-line px-3 text-left font-medium">For</th><th class="border-b border-line px-3 text-left font-medium">Account</th><th class="border-b border-line px-3 text-right font-medium">Amount (BDT)</th><th class="border-b border-line px-3 text-left font-medium">Receipt</th><th class="border-b border-line px-3 text-left font-medium">Replenished by</th></tr></thead>
                        <tbody>
                            <tr v-for="v in vouchers" :key="v.id" class="h-(--row-h)">
                                <td class="border-b border-line px-3">{{ v.number }}</td><td class="border-b border-line px-3">{{ formatDate(v.date) }}</td><td class="border-b border-line px-3">{{ v.payee }}</td>
                                <td class="border-b border-line px-3">{{ v.description }}</td><td class="border-b border-line px-3 text-ink-2">{{ v.account }}</td><td class="num border-b border-line px-3">{{ formatMoney(v.amount) }}</td>
                                <td class="border-b border-line px-3"><a v-for="d in v.receipts" :key="d.url" :href="d.url" class="text-accent-text hover:underline">{{ d.name }}</a></td>
                                <td class="border-b border-line px-3 text-ink-2">{{ v.replenishment ?? 'Not yet' }}</td>
                            </tr>
                            <tr v-if="vouchers.length === 0"><td colspan="8" class="px-3 py-6 text-center text-ui text-ink-2">No vouchers paid from this float yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="counts.length">
                <h2 class="mb-2 text-ui font-medium">Cash counts</h2>
                <ul class="rounded-panel border border-line text-ui">
                    <li v-for="(c, i) in counts" :key="i" class="flex flex-wrap gap-4 border-b border-line px-3 py-2 last:border-b-0">
                        <span class="w-28">{{ formatDate(c.date) }}</span><span>counted {{ formatMoney(c.counted) }} against {{ formatMoney(c.expected) }}</span>
                        <span :class="c.difference === '0.00' ? 'text-ok' : 'text-danger'">{{ c.difference === '0.00' ? 'agrees' : `difference ${formatMoney(c.difference)}` }}</span>
                        <span class="text-ink-2">{{ c.by }}<template v-if="c.note"> — {{ c.note }}</template></span>
                    </li>
                </ul>
            </section>
        </div>

        <Drawer v-model:open="spending" title="Pay a voucher">
            <FormLayout submit-label="Review the journal" :dirty="voucher.isDirty" :processing="voucher.processing" :error="(voucher.errors as Record<string, string>).form" @submit="reviewVoucher" @cancel="spending = false">
                <p class="text-ui text-ink-2">Cash on hand {{ formatMoney(float.on_hand) }} BDT.</p>
                <Field id="voucher_date" label="Date" :error="voucher.errors.voucher_date"><DateInput v-model="voucher.voucher_date" /></Field>
                <Field id="payee" label="Paid to" :error="voucher.errors.payee"><TextInput v-model="voucher.payee" /></Field>
                <Field id="description" label="For" :error="voucher.errors.description"><TextInput v-model="voucher.description" /></Field>
                <Field id="account_id" label="Expense account" :error="voucher.errors.account_id"><SelectInput id="account_id" v-model="voucher.account_id" placeholder="Choose an account" :options="accounts.map((a) => ({ value: a.id, label: a.label }))" /></Field>
                <Field id="amount" label="Amount (BDT)" :error="voucher.errors.amount"><MoneyInput v-model="voucher.amount" /></Field>
                <Field id="receipt" label="Receipt photo" optional :error="voucher.errors.receipt">
                    <input id="receipt" type="file" accept="image/*,application/pdf" capture="environment" class="text-ui" @change="(e) => (voucher.receipt = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                </Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="replenishing" title="Request replenishment">
            <FormLayout submit-label="Request replenishment" :dirty="true" :processing="replenish.processing" :error="(replenish.errors as Record<string, string>).form" @submit="replenish.post(`/petty-cash/${float.id}/replenishments`, { onSuccess: () => (replenishing = false) })" @cancel="replenishing = false">
                <p class="text-ui text-ink-2">Tops the float back up by the {{ formatMoney(float.to_replenish) }} BDT of vouchers paid since the last replenishment. The finance manager approves it.</p>
                <Field id="bank_account_id" label="Pay from" :error="replenish.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="replenish.bank_account_id" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="counting" title="Count the cash">
            <FormLayout submit-label="Review" :dirty="count.form.isDirty" :processing="count.form.processing" :error="(count.form.errors as Record<string, string>).form" @submit="count.review" @cancel="counting = false">
                <p class="text-ui text-ink-2">The book says {{ formatMoney(float.on_hand) }} BDT. A difference is posted as a cash shortage or surplus.</p>
                <Field id="counted_on" label="Counted on" :error="count.form.errors.counted_on"><DateInput v-model="count.form.counted_on" /></Field>
                <Field id="counted" label="Cash counted (BDT)" :error="count.form.errors.counted"><MoneyInput v-model="count.form.counted" /></Field>
                <Field id="note" label="Note" optional :error="count.form.errors.note"><TextInput v-model="count.form.note" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="voucherPreviewOpen" :result="voucherPreview" title="Pay this voucher?" confirm-label="Pay" currency="BDT" :processing="voucher.processing" @confirm="postVoucher" />
        <JournalPreviewDialog v-model:open="count.previewOpen.value" :result="count.preview.value" title="Record this count?" confirm-label="Record the count" currency="BDT" :processing="count.form.processing" @confirm="count.post" />
        <JournalPreviewDialog v-model:open="confirm.state.open" :result="confirm.state.result" :title="confirm.state.title" :confirm-label="confirm.state.label" currency="BDT" :processing="confirm.state.processing" @confirm="confirm.confirm" />
    </AppLayout>
</template>
