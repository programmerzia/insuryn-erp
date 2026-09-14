<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AuditRow, TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/** The supplier page (addendum v2 §B.4): terms, tax profile and bank account, its bills, and the supplier statement with a running balance. */
const props = defineProps<{
    supplier: { id: string; code: string; name: string; category: string; category_label: string; rates: { vat: string; vds: string; tds: string }; status: string; terms: number; tin: string | null; bin: string | null;
        vat_registered: boolean; default_account_id: string | null; default_account: string | null; bank_name: string | null; bank_branch: string | null; routing_no: string | null; account_name: string | null;
        account_no_masked: string | null; mobile: string | null; email: string | null; address: string | null; owed: string };
    bills: { id: string; number: string | null; reference: string; bill_date: string; due_date: string; payable: string; outstanding: string; status: string }[];
    statement: { from: string; to: string; opening: string; closing: string; lines: { date: string; kind: string; document: string; reference: string; link: string | null; debit: string | null; credit: string | null; balance: string }[] };
    categories: { value: string; label: string }[];
    canManage: boolean;
    canEnterBills: boolean;
    timeline?: TimelineEntry[];
    audit?: AuditRow[];
}>();

const facts = computed(() => [
    { label: 'Owed (BDT)', value: formatMoney(props.supplier.owed), num: true },
    { label: 'Terms', value: `${props.supplier.terms} days` },
    { label: 'Category', value: props.supplier.category_label },
    { label: 'Deducted at source', value: `VDS ${props.supplier.rates.vds}% · TDS ${props.supplier.rates.tds}%` },
]);
const editing = ref(false);
const form = useForm({ category: props.supplier.category, status: props.supplier.status, payment_terms_days: String(props.supplier.terms), tin: props.supplier.tin ?? '', bin: props.supplier.bin ?? '',
    bank_name: props.supplier.bank_name ?? '', bank_branch: props.supplier.bank_branch ?? '', routing_no: props.supplier.routing_no ?? '', account_name: props.supplier.account_name ?? '', account_no: '' });
const period = ref({ from: props.statement.from, to: props.statement.to });
function reloadStatement(): void {
    router.get(`/payables/suppliers/${props.supplier.id}`, { from: period.value.from, to: period.value.to, tab: 'transactions' }, { preserveScroll: true, preserveState: true, only: ['statement'] });
}
const cell = 'border-b border-line px-3';
</script>

<template>
    <AppLayout help="bank" :title="supplier.name">
        <ObjectPage
            :title="supplier.name"
            :subtitle="`${supplier.code} · ${supplier.category_label}`"
            :status="supplier.status"
            :facts="facts"
            :crumbs="[{ label: 'Suppliers', href: '/payables/suppliers' }]"
            currency="BDT"
            :timeline="timeline"
            :audit="audit"
            :hidden-tabs="['accounting', 'documents']"
            transactions-label="Statement"
        >
            <template #actions>
                <button v-if="canManage" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="editing = true">Edit supplier</button>
                <Link v-if="canEnterBills" :href="`/payables/bills/create?supplier=${supplier.id}`" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Enter a bill</Link>
            </template>
            <template #overview>
                <div class="grid max-w-[1100px] gap-6 lg:grid-cols-2">
                    <section>
                        <h2 class="mb-2 text-ui font-medium">Tax profile</h2>
                        <dl class="grid grid-cols-[160px_1fr] gap-y-1 text-ui">
                            <dt class="text-ink-2">TIN</dt><dd>{{ supplier.tin ?? '—' }}</dd>
                            <dt class="text-ink-2">BIN</dt><dd>{{ supplier.bin ?? '—' }}</dd>
                            <dt class="text-ink-2">VAT on bills</dt><dd>{{ supplier.rates.vat }}% (placeholder, verify)</dd>
                            <dt class="text-ink-2">VAT deducted at source</dt><dd>{{ supplier.rates.vds }}% of the net amount</dd>
                            <dt class="text-ink-2">Tax deducted at source</dt><dd>{{ supplier.rates.tds }}% of the net amount</dd>
                            <dt class="text-ink-2">Default expense account</dt><dd>{{ supplier.default_account ?? '—' }}</dd>
                        </dl>
                    </section>
                    <section>
                        <h2 class="mb-2 text-ui font-medium">Paid into</h2>
                        <dl class="grid grid-cols-[160px_1fr] gap-y-1 text-ui">
                            <dt class="text-ink-2">Bank</dt><dd>{{ [supplier.bank_name, supplier.bank_branch].filter(Boolean).join(', ') || 'No bank account' }}</dd>
                            <dt class="text-ink-2">Routing number</dt><dd class="tabular-nums">{{ supplier.routing_no ?? '—' }}</dd>
                            <dt class="text-ink-2">Account</dt><dd class="tabular-nums">{{ supplier.account_name ?? '' }} {{ supplier.account_no_masked ?? '' }}</dd>
                            <dt class="text-ink-2">Contact</dt><dd>{{ [supplier.mobile, supplier.email].filter(Boolean).join(' · ') || '—' }}</dd>
                        </dl>
                    </section>
                    <section class="lg:col-span-2">
                        <h2 class="mb-2 text-ui font-medium">Bills</h2>
                        <div class="overflow-x-auto border border-line">
                            <table class="w-full border-separate border-spacing-0 text-dense">
                                <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="cell" class="text-left font-medium">Bill</th><th :class="cell" class="text-left font-medium">Invoice</th><th :class="cell" class="text-left font-medium">Bill date</th><th :class="cell" class="text-left font-medium">Due</th><th :class="cell" class="text-right font-medium">Payable</th><th :class="cell" class="text-right font-medium">Outstanding</th><th :class="cell" class="text-left font-medium">Status</th></tr></thead>
                                <tbody>
                                    <tr v-for="b in bills" :key="b.id" class="h-(--row-h)">
                                        <td :class="cell"><Link :href="`/payables/bills/${b.id}`" class="text-accent-text hover:underline">{{ b.number ?? 'Draft' }}</Link></td>
                                        <td :class="cell">{{ b.reference }}</td><td :class="cell">{{ formatDate(b.bill_date) }}</td><td :class="cell">{{ formatDate(b.due_date) }}</td>
                                        <td :class="cell" class="num">{{ formatMoney(b.payable) }}</td><td :class="cell" class="num">{{ formatMoney(b.outstanding) }}</td><td :class="cell"><StatusBadge :status="b.status" /></td>
                                    </tr>
                                    <tr v-if="bills.length === 0"><td colspan="7" class="px-3 py-4 text-ui text-ink-2">No bills from this supplier yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </template>
            <template #transactions>
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <Field id="statement_from" label="From"><DateInput v-model="period.from" /></Field>
                    <Field id="statement_to" label="To"><DateInput v-model="period.to" /></Field>
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="reloadStatement">Show statement</button>
                </div>
                <div class="max-w-[1000px] overflow-x-auto border border-line">
                    <table class="w-full border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th :class="cell" class="text-left font-medium">Date</th><th :class="cell" class="text-left font-medium">What</th><th :class="cell" class="text-left font-medium">Document</th><th :class="cell" class="text-left font-medium">Reference</th><th :class="cell" class="text-right font-medium">Paid (BDT)</th><th :class="cell" class="text-right font-medium">Billed (BDT)</th><th :class="cell" class="text-right font-medium">Balance owed</th></tr></thead>
                        <tbody>
                            <tr class="h-(--row-h)"><td :class="cell">{{ formatDate(statement.from) }}</td><td :class="cell" colspan="5" class="text-ink-2">Balance brought forward</td><td :class="cell" class="num">{{ formatMoney(statement.opening) }}</td></tr>
                            <tr v-for="(l, index) in statement.lines" :key="index" class="h-(--row-h)">
                                <td :class="cell">{{ formatDate(l.date) }}</td><td :class="cell">{{ l.kind }}</td>
                                <td :class="cell"><Link v-if="l.link" :href="l.link" class="text-accent-text hover:underline">{{ l.document }}</Link><template v-else>{{ l.document }}</template></td>
                                <td :class="cell" class="text-ink-2">{{ l.reference }}</td>
                                <td :class="cell" class="num">{{ l.debit ? formatMoney(l.debit) : '' }}</td><td :class="cell" class="num">{{ l.credit ? formatMoney(l.credit) : '' }}</td><td :class="cell" class="num">{{ formatMoney(l.balance) }}</td>
                            </tr>
                            <tr class="h-(--row-h) font-medium"><td :class="cell">{{ formatDate(statement.to) }}</td><td :class="cell" colspan="5">Balance owed</td><td :class="cell" class="num">{{ formatMoney(statement.closing) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </ObjectPage>
        <Drawer v-model:open="editing" :title="`Edit ${supplier.name}`">
            <FormLayout submit-label="Save supplier" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.put(`/payables/suppliers/${supplier.id}`, { onSuccess: () => (editing = false) })" @cancel="editing = false">
                <Field id="edit_status" label="Status" :error="form.errors.status"><SelectInput id="edit_status" v-model="form.status" :options="[{ value: 'active', label: 'Active' }, { value: 'on_hold', label: 'On hold (no new payments)' }, { value: 'blocked', label: 'Blocked' }]" /></Field>
                <Field id="edit_category" label="Category" :error="form.errors.category"><SelectInput id="edit_category" v-model="form.category" :options="categories" /></Field>
                <Field id="edit_terms" label="Payment terms (days)" :error="form.errors.payment_terms_days"><TextInput v-model="form.payment_terms_days" inputmode="numeric" /></Field>
                <Field id="edit_tin" label="TIN" optional :error="form.errors.tin"><TextInput v-model="form.tin" /></Field>
                <Field id="edit_bin" label="BIN" optional :error="form.errors.bin"><TextInput v-model="form.bin" /></Field>
                <Field id="edit_bank" label="Bank" optional :error="form.errors.bank_name"><TextInput v-model="form.bank_name" /></Field>
                <Field id="edit_branch" label="Bank branch" optional :error="form.errors.bank_branch"><TextInput v-model="form.bank_branch" /></Field>
                <Field id="edit_routing" label="Routing number" optional :error="form.errors.routing_no"><TextInput v-model="form.routing_no" :maxlength="9" /></Field>
                <Field id="edit_account_name" label="Account name" optional :error="form.errors.account_name"><TextInput v-model="form.account_name" /></Field>
                <Field id="edit_account_no" label="New account number" hint="Leave empty to keep the current account." optional :error="form.errors.account_no"><TextInput v-model="form.account_no" inputmode="numeric" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
