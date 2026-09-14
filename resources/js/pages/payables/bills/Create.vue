<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import LookupInput, { type LookupResult } from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, parseMoney } from '@/lib/money';

/**
 * Enter a supplier bill (addendum v2 §B.4): the supplier's invoice number and dates, lines to expense accounts with VAT, and — for garage, surveyor or
 * hospital bills — the claim. VAT, VAT deducted at source and tax deducted at source follow the supplier's category (placeholder rates, verify); the VAT
 * on a line can be typed from the invoice. The payable is what the supplier receives after the deductions.
 */
interface SupplierOption { id: string; label: string; terms: number; vat_bp: number; vds_bp: number; tds_bp: number; category: string; default_account: LookupResult | null }
const props = defineProps<{ today: string; suppliers: SupplierOption[]; branches: { value: string; label: string }[]; claims: { value: string; label: string; policy_id: string }[]; inputVatRecoverable: boolean; supplierId: string | null }>();

interface Line { description: string; account_id: string; net: string; vat: string; claim_id: string; policy_id: string }
const blankLine = (): Line => ({ description: '', account_id: '', net: '', vat: '', claim_id: '', policy_id: '' });
const form = useForm({ supplier_id: props.supplierId ?? '', branch_id: props.branches[0]?.value ?? '', supplier_reference: '', bill_date: props.today, due_date: '', description: '', send_for_approval: true, lines: [blankLine()] as Line[] });
const lineAccounts = ref<(LookupResult | null)[]>([null]);
const lineKeys = ref<number[]>([0]);
let nextKey = 1;
const supplier = computed(() => props.suppliers.find((s) => s.id === form.supplier_id) ?? null);

function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}
watch(() => [form.supplier_id, form.bill_date] as const, ([id, date]) => {
    const s = props.suppliers.find((x) => x.id === id);
    if (!s || !date) return;
    form.due_date = addDays(date, s.terms);
    form.lines.forEach((line, index) => {
        if (line.account_id === '' && s.default_account) {
            line.account_id = s.default_account.id;
            lineAccounts.value[index] = s.default_account;
            lineKeys.value[index] = nextKey++;
        }
    });
}, { immediate: true });

function addLine(): void {
    form.lines.push(blankLine());
    lineAccounts.value.push(supplier.value?.default_account ?? null);
    if (supplier.value?.default_account) form.lines[form.lines.length - 1]!.account_id = supplier.value.default_account.id;
    lineKeys.value.push(nextKey++);
}
function removeLine(index: number): void {
    form.lines.splice(index, 1);
    lineAccounts.value.splice(index, 1);
    lineKeys.value.splice(index, 1);
}

/** Basis points of an amount, half to even, on BigInt minor units (as the server computes). */
function pct(base: bigint, bp: number): bigint {
    const product = base * BigInt(bp);
    let quotient = product / 10000n;
    const twice = 2n * (product % 10000n);
    if (twice > 10000n || (twice === 10000n && quotient % 2n !== 0n)) quotient += 1n;
    return quotient;
}
const taxes = computed(() => form.lines.map((line) => {
    const net = parseMoney(line.net) ?? 0n;
    const s = supplier.value;
    const typed = line.vat.trim() === '' ? null : parseMoney(line.vat);
    const vat = typed ?? (s ? pct(net, s.vat_bp) : 0n);
    const vds = s ? (pct(net, s.vds_bp) < vat ? pct(net, s.vds_bp) : vat) : 0n;
    const tds = s ? pct(net, s.tds_bp) : 0n;
    return { net, vat, vds, tds };
}));
const totals = computed(() => taxes.value.reduce((t, x) => ({ net: t.net + x.net, vat: t.vat + x.vat, vds: t.vds + x.vds, tds: t.tds + x.tds }), { net: 0n, vat: 0n, vds: 0n, tds: 0n }));
const payable = computed(() => totals.value.net + totals.value.vat - totals.value.vds - totals.value.tds);
const errors = computed(() => form.errors as Record<string, string>);

function save(submit: boolean): void {
    form.send_for_approval = submit;
    form.transform((data) => ({ ...data, lines: data.lines.map((l) => ({ ...l, policy_id: l.claim_id ? (props.claims.find((c) => c.value === l.claim_id)?.policy_id ?? '') : '' })) }))
        .post('/payables/bills');
}
</script>

<template>
    <AppLayout help="bank" title="Enter a supplier bill">
        <Breadcrumb :base="[{ label: 'Supplier bills', href: '/payables/bills' }]" />
        <PageHeader title="Enter a supplier bill" />
        <FormLayout wide submit-label="Save and send for approval" drafts cancel-href="/payables/bills" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="save(true)" @save-draft="save(false)">
            <div class="grid gap-4 md:grid-cols-2">
                <Field id="supplier_id" label="Supplier" :hint="supplier ? `${supplier.category} · terms ${supplier.terms} days` : 'Add a supplier first from Payables → Suppliers.'" :error="form.errors.supplier_id">
                    <SelectInput id="supplier_id" v-model="form.supplier_id" placeholder="Choose the supplier" :options="suppliers.map((s) => ({ value: s.id, label: s.label }))" />
                </Field>
                <Field id="supplier_reference" label="Supplier's invoice number" :error="form.errors.supplier_reference"><TextInput v-model="form.supplier_reference" :maxlength="64" /></Field>
                <Field id="bill_date" label="Bill date" hint="The bill posts on this date." :error="form.errors.bill_date"><DateInput v-model="form.bill_date" /></Field>
                <Field id="due_date" label="Due date" hint="From the supplier's payment terms; change it if the invoice says otherwise." :error="form.errors.due_date"><DateInput v-model="form.due_date" /></Field>
                <Field id="branch_id" label="Branch" :error="form.errors.branch_id"><SelectInput id="branch_id" v-model="form.branch_id" :options="branches" /></Field>
                <Field id="description" label="Description" optional :error="form.errors.description"><TextInput v-model="form.description" placeholder="September office rent" /></Field>
            </div>

            <section>
                <h2 class="mb-2 text-ui font-medium">Lines</h2>
                <div class="grid gap-3">
                    <div v-for="(line, index) in form.lines" :key="lineKeys[index]" class="grid gap-2 rounded-control border border-line p-3 md:grid-cols-[1.4fr_1.4fr_1fr_1fr_auto]">
                        <Field :id="`line_${index}_description`" label="What for" :error="errors[`lines.${index}.description`]"><TextInput v-model="line.description" placeholder="Office rent, Gulshan Avenue" /></Field>
                        <Field :id="`line_${index}_account`" label="Expense account" :error="errors[`lines.${index}.account_id`]">
                            <LookupInput :id="`line_${index}_account`" :key="`acc-${lineKeys[index]}`" v-model="line.account_id" type="account" :initial="lineAccounts[index] ?? null" placeholder="Code or name" @selected="lineAccounts[index] = $event" />
                        </Field>
                        <Field :id="`line_${index}_net`" label="Amount before VAT" :error="errors[`lines.${index}.net`]"><MoneyInput :id="`line_${index}_net`" v-model="line.net" /></Field>
                        <Field :id="`line_${index}_vat`" label="VAT" :hint="line.vat.trim() === '' ? `${formatMinor(taxes[index]?.vat ?? 0n)} at the category rate` : 'As on the invoice'" :error="errors[`lines.${index}.vat`]">
                            <MoneyInput :id="`line_${index}_vat`" v-model="line.vat" :placeholder="formatMinor(taxes[index]?.vat ?? 0n)" />
                        </Field>
                        <div class="flex items-end">
                            <button v-if="form.lines.length > 1" type="button" class="h-8 rounded-control px-2 text-ui text-ink-2 hover:bg-surface-2" :aria-label="`Remove line ${index + 1}`" @click="removeLine(index)">Remove</button>
                        </div>
                        <Field :id="`line_${index}_claim`" label="Claim" optional hint="For a garage, surveyor or hospital bill on a claim." :error="errors[`lines.${index}.claim_id`]" class="md:col-span-2">
                            <SelectInput :id="`line_${index}_claim`" v-model="line.claim_id" placeholder="No claim" :options="claims" />
                        </Field>
                        <p class="self-end text-dense text-ink-2 md:col-span-3">Deducted at source: VAT {{ formatMinor(taxes[index]?.vds ?? 0n) }} · tax {{ formatMinor(taxes[index]?.tds ?? 0n) }}</p>
                    </div>
                </div>
                <button type="button" class="mt-2 h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="addLine">Add a line</button>
            </section>

            <dl class="grid max-w-[420px] grid-cols-[1fr_auto] gap-y-1 rounded-control border border-line p-3 text-ui">
                <dt class="text-ink-2">Net</dt><dd class="text-right tabular-nums">{{ formatMinor(totals.net) }}</dd>
                <dt class="text-ink-2">VAT {{ inputVatRecoverable ? '(reclaimable)' : '(part of the cost)' }}</dt><dd class="text-right tabular-nums">{{ formatMinor(totals.vat) }}</dd>
                <dt class="text-ink-2">Gross</dt><dd class="text-right tabular-nums">{{ formatMinor(totals.net + totals.vat) }}</dd>
                <dt class="text-ink-2">Less VAT deducted at source</dt><dd class="text-right tabular-nums">{{ formatMinor(totals.vds) }}</dd>
                <dt class="text-ink-2">Less tax deducted at source</dt><dd class="text-right tabular-nums">{{ formatMinor(totals.tds) }}</dd>
                <dt class="font-medium">Payable to the supplier (BDT)</dt><dd class="text-right font-medium tabular-nums">{{ formatMinor(payable) }}</dd>
            </dl>
        </FormLayout>
    </AppLayout>
</template>
