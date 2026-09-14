<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import LookupInput from '@/components/forms/LookupInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor } from '@/lib/money';

/** New payment run (slice 2.4): the posted bills due by a date (optionally one supplier's) that can be paid now; tick the ones to pay. */
interface DueBill { id: string; number: string | null; supplier: string; reference: string; due_date: string; amount: string; amount_minor: number }
const props = defineProps<{ filters: { due_by: string; supplier_id: string }; today: string; bankAccounts: { id: string; label: string }[]; supplier: { id: string; label: string } | null; bills: DueBill[] }>();

const filters = ref({ ...props.filters });
function reload(): void {
    router.get('/payables/payment-runs/create', filters.value, { preserveState: false });
}
const form = useForm({ bank_account_id: props.bankAccounts[0]?.id ?? '', pay_date: props.today, bill_ids: props.bills.map((b) => b.id), send_for_approval: true });
const total = computed(() => props.bills.filter((b) => form.bill_ids.includes(b.id)).reduce((sum, b) => sum + BigInt(b.amount_minor), 0n));
const errors = computed(() => form.errors as Record<string, string>);
function save(submit: boolean): void {
    form.send_for_approval = submit;
    form.post('/payables/payment-runs');
}
function toggleAll(checked: boolean): void {
    form.bill_ids = checked ? props.bills.map((b) => b.id) : [];
}
const cell = 'border-b border-line px-3';
</script>

<template>
    <AppLayout help="payables" title="New payment run">
        <Breadcrumb :base="[{ label: 'Payment runs', href: '/payables/payment-runs' }]" />
        <PageHeader title="New payment run" description="Bills are paid in full into each supplier's bank account as it is now; the file for the bank comes after release." />
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <Field id="due_by" label="Due on or before"><DateInput v-model="filters.due_by" /></Field>
            <Field id="supplier_filter" label="Supplier"><LookupInput id="supplier_filter" v-model="filters.supplier_id" type="supplier" :initial="supplier" placeholder="All suppliers" /></Field>
            <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="reload">Show due bills</button>
        </div>
        <p v-if="errors.form" class="mb-3 border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ errors.form }}</p>
        <div class="max-w-[1100px] overflow-x-auto border border-line">
            <table class="w-full border-separate border-spacing-0 text-dense">
                <thead class="bg-surface-2 text-ink-2">
                    <tr class="h-(--row-h)">
                        <th :class="cell" class="w-10 text-left"><input type="checkbox" aria-label="Choose every bill" :checked="form.bill_ids.length === bills.length && bills.length > 0" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th>
                        <th :class="cell" class="text-left font-medium">Supplier</th><th :class="cell" class="text-left font-medium">Bill</th><th :class="cell" class="text-left font-medium">Invoice</th><th :class="cell" class="text-left font-medium">Due</th><th :class="cell" class="text-right font-medium">To pay (BDT)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in bills" :key="b.id" class="h-(--row-h)">
                        <td :class="cell"><input v-model="form.bill_ids" type="checkbox" :value="b.id" :aria-label="`Pay ${b.number}`" /></td>
                        <td :class="cell">{{ b.supplier }}</td><td :class="cell">{{ b.number }}</td><td :class="cell" class="text-ink-2">{{ b.reference }}</td><td :class="cell">{{ formatDate(b.due_date) }}</td><td :class="cell" class="num">{{ formatMoney(b.amount) }}</td>
                    </tr>
                    <tr v-if="bills.length === 0"><td colspan="6" class="px-3 py-4 text-ui text-ink-2">No posted bills are due by this date, or their suppliers have no bank account or are on hold.</td></tr>
                </tbody>
                <tfoot><tr class="h-(--row-h) font-medium"><td :class="cell" colspan="5">{{ form.bill_ids.length }} bills chosen</td><td :class="cell" class="num">{{ formatMinor(total) }}</td></tr></tfoot>
            </table>
        </div>
        <p v-if="errors.bill_ids" class="mt-2 text-ui text-danger">{{ errors.bill_ids }}</p>
        <div class="mt-4 grid max-w-[560px] gap-4">
            <Field id="bank_account_id" label="Pay from" :error="errors.bank_account_id"><SelectInput id="bank_account_id" v-model="form.bank_account_id" placeholder="Choose a bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" /></Field>
            <Field id="pay_date" label="Pay date" hint="The payment posts on this date." :error="errors.pay_date"><DateInput v-model="form.pay_date" /></Field>
            <div class="flex gap-2 border-t border-line pt-4">
                <button type="button" :disabled="form.processing || form.bill_ids.length === 0" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" @click="save(true)">Prepare and send for approval</button>
                <button type="button" :disabled="form.processing || form.bill_ids.length === 0" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2 disabled:opacity-50" @click="save(false)">Save as draft</button>
            </div>
        </div>
    </AppLayout>
</template>
