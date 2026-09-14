<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Lock, Plus, X } from 'lucide-vue-next';
import { computed, nextTick } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import Stepper from '@/components/forms/Stepper.vue';
import TextInput from '@/components/forms/TextInput.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { normalSideFor } from '@/lib/accountCreate';
import { formatDate } from '@/lib/format';
import type { SharedProps } from '@/types/shared';

/**
 * Setup wizard (session S1, market cross-check G9): company and branches → fiscal year and currency → chart of accounts → first product →
 * users and roles → done. Every step saves on its own and can be reopened; a step someone else owns says who.
 */
interface Step { id: string; label: string; done: boolean; allowed: boolean; owner: string | null }
interface AccountRow { code: string; name: string; type: string; normal_side: string; is_control: boolean; control_subledger: string | null; role: string | null }
const props = defineProps<{
    steps: Step[];
    current: string;
    finished: boolean;
    company: { code: string; name: string; timezone: string; timezones: string[]; branches: { code: string; name: string }[] };
    fiscalYear: { opened: boolean; first_month: string; base_currency: string; periods: number };
    chartOfAccounts: { template: string; templates: { id: string; name: string; description: string }[]; rows: AccountRow[]; imported: number | null; roles: Record<string, string> };
    product: { linesOfBusiness: Record<string, string>; existing: { code: string; name: string; insurance_class: string }[]; vatInForce: number | null };
    users: { roles: { code: string; name: string }[]; existing: { name: string; email: string }[] };
    approvals: { defaults: { label: string; amount: string; approvers: string }[]; existing: number };
}>();

const page = usePage<SharedProps>();
const index = computed(() => props.steps.findIndex((s) => s.id === props.current));
const step = computed(() => props.steps[index.value]);
const go = (i: number) => router.get('/setup', { step: props.steps[i]?.id }, { preserveScroll: false });
const skip = () => go(Math.min(index.value + 1, props.steps.length - 1));
const errors = computed(() => page.props.errors as Record<string, string>);

const company = useForm({ code: props.company.code, name: props.company.name, timezone: props.company.timezone, branches: props.company.branches.length ? props.company.branches.map((b) => ({ ...b })) : [{ code: 'HO', name: 'Head Office' }] });
const fiscal = useForm({ first_month: props.fiscalYear.first_month, base_currency: props.fiscalYear.base_currency });
const coa = useForm({ rows: props.chartOfAccounts.rows.map((r) => ({ ...r })) });
const product = useForm({ code: '', name: '', lob: 'motor', insurance_class: 'non_life', term_months: '12', effective_from: props.fiscalYear.opened ? `${props.fiscalYear.first_month}-01` : '',
    vat_rate_percent: props.product.vatInForce === null ? '15' : String(props.product.vatInForce / 100), vat_inclusive: true });
const people = useForm({ users: [{ name: '', email: '', role: 'branch_officer' }] });

const types = ['asset', 'liability', 'equity', 'income', 'expense'].map((t) => ({ value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }));
const sides = [{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }];
const terms = [{ value: '12', label: '12 months' }, { value: '6', label: '6 months' }, { value: '3', label: '3 months' }, { value: '1', label: '1 month' }];
const monthLabel = (month: string) => (month ? new Date(`${month}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : '—');
const lastMonth = computed(() => {
    if (!fiscal.first_month) return '';
    const [y, m] = fiscal.first_month.split('-').map(Number);
    const end = new Date(y!, m! - 1 + 11, 1);
    return `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}`;
});

/** UX U3: a new row at the end of the table, its code field focused, so adding reads as one action. */
async function addAccount(): Promise<void> {
    coa.rows.push({ code: '', name: '', type: 'expense', normal_side: 'debit', is_control: false, control_subledger: null, role: null });
    await nextTick();
    document.getElementById(`coa_code_${coa.rows.length - 1}`)?.focus();
}
const accountFields = ['code', 'name', 'type', 'normal_side', 'control_subledger', 'role'] as const;
const rowErrors = (i: number) => accountFields.map((field) => errors.value[`rows.${i}.${field}`]).filter((m): m is string => !!m);
const rowsWithErrors = computed(() => coa.rows.filter((_, i) => rowErrors(i).length > 0).length);
const roleRows = computed(() => coa.rows.filter((r) => r.role).length);
/** UX U3 sweep: the chart of accounts belongs to the company and the fiscal year's book, so the step says which of them to save first. */
const chartNeeds = computed(() => props.steps.filter((s) => (s.id === 'company' || s.id === 'fiscal_year') && !s.done));
/** UX U3 sweep: the button that moves on without saving says Continue once the step is saved, Skip for now before. */
const skipLabel = computed(() => (step.value?.done ? 'Continue' : 'Skip for now'));
</script>

<template>
    <AppLayout title="Set up your company">
        <div class="mb-4 flex items-baseline gap-3">
            <h1 class="text-title font-semibold">Set up your company</h1>
            <p class="text-ui text-ink-2">Each step saves on its own. You can come back to any of them from Admin → Setup.</p>
        </div>
        <Stepper :steps="steps" :current="index" free :wide="current === 'chart_of_accounts'" data-tour="setup-steps" @go="go">
            <div v-if="step && !step.allowed" class="grid max-w-[560px] gap-3">
                <h2 class="text-section font-semibold">{{ step.label }}</h2>
                <p class="text-body">This step belongs to the {{ step.owner }}. Invite one in <em>Users and roles</em>; they sign in and finish it here.</p>
                <div><Button variant="secondary" @click="skip">Skip for now</Button></div>
            </div>

            <FormLayout v-else-if="current === 'company'" submit-label="Save and continue" :cancel-label="skipLabel" :dirty="company.isDirty" :processing="company.processing" @submit="company.post('/setup/company')" @cancel="skip">
                <h2 class="text-section font-semibold">Company and branches</h2>
                <p class="-mt-2 text-ui text-ink-2">The legal entity your books are kept for, and the offices that sell policies and take payments.</p>
                <Field id="company_name" label="Company name" :error="company.errors.name"><TextInput v-model="company.name" /></Field>
                <Field id="company_code" label="Short code" :error="company.errors.code" hint="Appears on reports, for example ACME."><TextInput v-model="company.code" :maxlength="16" /></Field>
                <!-- Slice 2.1b (D-54): business dates — today on forms, month end, expiry and the nightly runs — follow this time zone. -->
                <Field id="company_timezone" label="Time zone" :error="company.errors.timezone" hint="Business dates follow this clock: what counts as today, month end and the nightly runs.">
                    <SelectInput v-model="company.timezone" :options="props.company.timezones.map((z) => ({ value: z, label: z.replace(/_/g, ' ') }))" />
                </Field>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-ui font-medium">Branches</legend>
                    <div v-for="(branch, i) in company.branches" :key="i" class="grid grid-cols-[96px_minmax(0,1fr)_32px] items-start gap-2">
                        <Field :id="`branch_code_${i}`" label="Code" :error="errors[`branches.${i}.code`]"><TextInput v-model="branch.code" :maxlength="16" /></Field>
                        <Field :id="`branch_name_${i}`" label="Name" :error="errors[`branches.${i}.name`]"><TextInput v-model="branch.name" /></Field>
                        <Button variant="ghost" size="icon" class="mt-6" :aria-label="`Remove branch ${branch.code || i + 1}`" :disabled="company.branches.length === 1" @click="company.branches.splice(i, 1)"><X :size="16" /></Button>
                    </div>
                    <p v-if="company.errors.branches" class="text-dense text-danger" role="alert">{{ company.errors.branches }}</p>
                    <div><Button variant="ghost" size="sm" @click="company.branches.push({ code: '', name: '' })"><Plus :size="16" /> Add a branch</Button></div>
                </fieldset>
            </FormLayout>

            <FormLayout v-else-if="current === 'fiscal_year'" submit-label="Save and continue" :cancel-label="skipLabel" :dirty="fiscal.isDirty" :processing="fiscal.processing" @submit="fiscal.post('/setup/fiscal-year')" @cancel="skip">
                <h2 class="text-section font-semibold">Fiscal year and base currency</h2>
                <p class="-mt-2 text-ui text-ink-2">The year is split into twelve monthly periods. Each month is closed and locked at month end, so reported numbers stay reported.</p>
                <template v-if="fiscalYear.opened">
                    <p class="text-body">{{ fiscalYear.periods }} periods are open from {{ monthLabel(fiscalYear.first_month) }}. Periods cannot be moved once opened.</p>
                </template>
                <Field v-else id="first_month" label="First month of the fiscal year" :error="fiscal.errors.first_month" :hint="`Runs ${monthLabel(fiscal.first_month)} to ${monthLabel(lastMonth)}. Bangladesh insurers usually start in January or July.`">
                    <input id="first_month" v-model="fiscal.first_month" type="month" class="h-8 w-48 rounded-control border border-line-control bg-surface px-2 text-body text-ink" />
                </Field>
                <Field id="base_currency" label="Base currency" :error="fiscal.errors.base_currency" hint="The currency your ledger is kept in. It cannot change after the first posting.">
                    <div class="w-24"><TextInput v-model="fiscal.base_currency" :maxlength="3" /></div>
                </Field>
            </FormLayout>

            <div v-else-if="current === 'chart_of_accounts' && chartOfAccounts.imported !== null" class="grid max-w-[560px] gap-3">
                <h2 class="text-section font-semibold">Chart of accounts</h2>
                <p class="text-body">{{ chartOfAccounts.imported }} accounts are in place. Add, rename or deactivate accounts any time in Accounting → Chart of accounts.</p>
                <div class="flex gap-2">
                    <Button @click="skip">Continue</Button>
                    <Link href="/accounting/chart-of-accounts" class="inline-flex h-8 items-center px-3 text-ui text-accent-text hover:underline">Open the chart of accounts</Link>
                </div>
            </div>

            <FormLayout v-else-if="current === 'chart_of_accounts'" wide submit-label="Create these accounts" cancel-label="Skip for now" :dirty="coa.isDirty" :processing="coa.processing" @submit="coa.post('/setup/chart-of-accounts')" @cancel="skip">
                <h2 class="text-section font-semibold">Chart of accounts</h2>
                <div v-if="chartNeeds.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-l-2 border-warn bg-surface-2 px-3 py-2 text-ui" role="status">
                    <p class="min-w-0 flex-1">First save {{ chartNeeds.map((s) => `“${s.label}”`).join(' and ') }}: the accounts belong to the company and its fiscal year's book.</p>
                    <Button variant="secondary" size="sm" @click="go(steps.findIndex((s) => s.id === chartNeeds[0]!.id))">Go to {{ chartNeeds[0]!.label }}</Button>
                </div>
                <p class="-mt-2 text-ui text-ink-2">Start from the template, adjust the accounts in the table (rename them, change codes, add your own, remove what you do not use), then create them. You can change them later in Accounting → Chart of accounts.</p>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-ui font-medium">Template</legend>
                    <label v-for="t in chartOfAccounts.templates" :key="t.id" class="flex gap-2 rounded-panel border border-accent bg-accent-soft p-3 text-ui">
                        <input type="radio" name="template" :value="t.id" checked class="mt-0.5" />
                        <span><span class="font-medium">{{ t.name }}</span><br /><span class="text-ink-2">{{ t.description }}</span></span>
                    </label>
                </fieldset>
                <p v-if="errors.rows" class="text-ui text-danger" role="alert">{{ errors.rows }}</p>
                <p v-if="rowsWithErrors > 0" class="border-l-2 border-danger pl-3 text-ui text-danger" role="alert">
                    {{ rowsWithErrors === 1 ? '1 account needs' : `${rowsWithErrors} accounts need` }} a change before the chart can be created: see the message under {{ rowsWithErrors === 1 ? 'it' : 'each' }}.
                </p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <Button variant="secondary" size="sm" @click="addAccount"><Plus :size="16" /> Add account</Button>
                    <span class="text-dense text-ink-2">{{ coa.rows.length }} accounts. The {{ roleRows }} marked <Lock :size="12" class="inline align-[-1px]" aria-label="with a lock" /> are used by the system's accounting and stay.</span>
                    <Link href="/accounting/imports" class="ml-auto text-ui text-accent-text hover:underline">Import from a CSV file instead</Link>
                </div>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full min-w-[760px] table-fixed border-separate border-spacing-0 text-dense">
                        <colgroup><col style="width: 84px" /><col /><col style="width: 112px" /><col style="width: 96px" /><col style="width: 230px" /><col style="width: 36px" /></colgroup>
                        <thead class="bg-surface-2 text-ink-2">
                            <tr class="h-8 text-left">
                                <th class="border-b border-line px-2 font-medium">Code</th><th class="border-b border-line px-2 font-medium">Name</th>
                                <th class="border-b border-line px-2 font-medium">Type</th><th class="border-b border-line px-2 font-medium">Normal side</th>
                                <th class="border-b border-line px-2 font-medium">Used by the system for</th><th class="border-b border-line"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(row, i) in coa.rows" :key="i">
                                <tr class="align-top" :class="rowErrors(i).length ? 'bg-surface-2' : ''">
                                    <td class="px-1 py-1" :class="rowErrors(i).length ? '' : 'border-b border-line'">
                                        <input :id="`coa_code_${i}`" v-model="row.code" :aria-label="`Code of row ${i + 1}`" placeholder="Code" class="h-7 w-full rounded-control border border-line-control bg-surface px-1.5 tabular-nums placeholder:text-ink-2 aria-[invalid=true]:border-danger" :aria-invalid="!!errors[`rows.${i}.code`]" :aria-describedby="rowErrors(i).length ? `coa_errors_${i}` : undefined" />
                                    </td>
                                    <td class="px-1 py-1" :class="rowErrors(i).length ? '' : 'border-b border-line'">
                                        <input v-model="row.name" :aria-label="`Name of account ${row.code || `in row ${i + 1}`}`" placeholder="Account name" class="h-7 w-full rounded-control border border-line-control bg-surface px-1.5 placeholder:text-ink-2 aria-[invalid=true]:border-danger" :aria-invalid="!!errors[`rows.${i}.name`]" :aria-describedby="rowErrors(i).length ? `coa_errors_${i}` : undefined" />
                                    </td>
                                    <td class="px-1 py-1" :class="rowErrors(i).length ? '' : 'border-b border-line'">
                                        <select v-model="row.type" :aria-label="`Type of account ${row.code || `in row ${i + 1}`}`" class="h-7 w-full rounded-control border border-line-control bg-surface px-1 aria-[invalid=true]:border-danger" :aria-invalid="!!errors[`rows.${i}.type`]" @change="row.normal_side = normalSideFor(row.type)">
                                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1" :class="rowErrors(i).length ? '' : 'border-b border-line'">
                                        <select v-model="row.normal_side" :aria-label="`Normal side of account ${row.code || `in row ${i + 1}`}`" class="h-7 w-full rounded-control border border-line-control bg-surface px-1 aria-[invalid=true]:border-danger" :aria-invalid="!!errors[`rows.${i}.normal_side`]">
                                            <option v-for="s in sides" :key="s.value" :value="s.value">{{ s.label }}</option>
                                        </select>
                                    </td>
                                    <td class="truncate px-2 py-1.5 text-ink-2" :class="rowErrors(i).length ? '' : 'border-b border-line'" :title="row.role ? chartOfAccounts.roles[row.role] : undefined">{{ row.role ? chartOfAccounts.roles[row.role] : '—' }}</td>
                                    <td class="py-1 text-center" :class="rowErrors(i).length ? '' : 'border-b border-line'">
                                        <Button v-if="!row.role" variant="ghost" size="icon" :aria-label="`Remove account ${row.code || `in row ${i + 1}`}`" @click="coa.rows.splice(i, 1)"><X :size="14" /></Button>
                                        <span v-else class="inline-flex size-8 items-center justify-center text-ink-2" :title="`Stays: used by the system for ${chartOfAccounts.roles[row.role]}`"><Lock :size="14" aria-hidden="true" /><span class="sr-only">Stays: used by the system</span></span>
                                    </td>
                                </tr>
                                <tr v-if="rowErrors(i).length" class="bg-surface-2">
                                    <td colspan="6" class="border-b border-line px-2 pb-1.5">
                                        <div :id="`coa_errors_${i}`">
                                            <p v-for="message in rowErrors(i)" :key="message" class="text-danger" role="alert">{{ message }}</p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div><Button variant="secondary" size="sm" @click="addAccount"><Plus :size="16" /> Add account</Button></div>
            </FormLayout>

            <FormLayout v-else-if="current === 'product'" submit-label="Create product" :cancel-label="skipLabel" :dirty="product.isDirty" :processing="product.processing" @submit="product.post('/setup/product')" @cancel="skip">
                <h2 class="text-section font-semibold">First product</h2>
                <p class="-mt-2 text-ui text-ink-2">What you sell, such as Motor Comprehensive. Policies are issued against a product; its term and tax decide the premium's accounting.</p>
                <p v-if="props.product.existing.length" class="text-ui">Already set up: {{ props.product.existing.map((p: { name: string }) => p.name).join(", ") }}. Add another here or in Products.</p>
                <Field id="product_name" label="Product name" :error="product.errors.name"><TextInput v-model="product.name" placeholder="Motor Comprehensive" /></Field>
                <Field id="product_code" label="Short code" :error="product.errors.code" hint="Used in policy lists, for example MOTOR."><TextInput v-model="product.code" :maxlength="32" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="product_lob" label="Class of business" :error="product.errors.lob"><SelectInput v-model="product.lob" :options="Object.entries(props.product.linesOfBusiness).map(([value, label]) => ({ value, label }))" /></Field>
                    <Field id="product_class" label="Insurance type" :error="product.errors.insurance_class"><SelectInput v-model="product.insurance_class" :options="[{ value: 'non_life', label: 'Non-life' }, { value: 'life', label: 'Life' }]" /></Field>
                    <Field id="product_term" label="Policy term" :error="product.errors.term_months"><SelectInput v-model="product.term_months" :options="terms" /></Field>
                    <Field id="product_from" label="Sold from" :error="product.errors.effective_from"><DateInput v-model="product.effective_from" /></Field>
                </div>
                <Field id="product_vat" label="VAT on premium (%)" :error="product.errors.vat_rate_percent" :hint="props.product.vatInForce !== null ? `A ${props.product.vatInForce / 100}% VAT rate is already in force and is used.` : 'Leave empty if the product carries no VAT.'" optional>
                    <div class="w-24"><TextInput v-model="product.vat_rate_percent" inputmode="decimal" /></div>
                </Field>
                <label class="flex items-center gap-2 text-ui"><input v-model="product.vat_inclusive" type="checkbox" /> The premium you enter already includes VAT</label>
                <p class="border-l-2 border-warn pl-3 text-ui text-ink-2">Stamp duty is not calculated yet. Record it outside the system for now.</p>
            </FormLayout>

            <FormLayout v-else-if="current === 'users'" submit-label="Send invitations" :cancel-label="skipLabel" :dirty="people.isDirty" :processing="people.processing" @submit="people.post('/setup/users')" @cancel="skip">
                <h2 class="text-section font-semibold">Users and roles</h2>
                <p class="-mt-2 text-ui text-ink-2">Each person gets an email to choose a password. A role decides what they can do; money moves only with two people (one prepares, another approves).</p>
                <p v-if="users.existing.length" class="text-ui">Already here: {{ users.existing.map((u) => u.name).join(', ') }}.</p>
                <div v-for="(person, i) in people.users" :key="i" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_150px_32px] items-start gap-2">
                    <Field :id="`user_name_${i}`" label="Name" :error="errors[`users.${i}.name`]"><TextInput v-model="person.name" /></Field>
                    <Field :id="`user_email_${i}`" label="Email" :error="errors[`users.${i}.email`]"><TextInput v-model="person.email" /></Field>
                    <Field :id="`user_role_${i}`" label="Role" :error="errors[`users.${i}.role`]"><SelectInput v-model="person.role" :options="users.roles.map((r) => ({ value: r.code, label: r.name }))" /></Field>
                    <Button variant="ghost" size="icon" class="mt-6" :aria-label="`Remove person ${i + 1}`" :disabled="people.users.length === 1" @click="people.users.splice(i, 1)"><X :size="16" /></Button>
                </div>
                <div><Button variant="ghost" size="sm" @click="people.users.push({ name: '', email: '', role: 'branch_officer' })"><Plus :size="16" /> Add a person</Button></div>
            </FormLayout>

            <div v-else-if="current === 'approvals'" class="grid max-w-[720px] gap-3">
                <h2 class="text-section font-semibold">Approval limits</h2>
                <p class="text-ui text-ink-2">Who approves money above which amount. Without a limit, one person other than the one who prepared it approves. These are suggested starting points: agree the real amounts with your management, then change them in Admin → Approval limits.</p>
                <div class="overflow-x-auto border border-line">
                    <table class="w-full min-w-[560px] border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2">
                            <tr class="h-8 text-left">
                                <th class="border-b border-line px-2 font-medium">What it approves</th>
                                <th class="border-b border-line px-2 font-medium">Amount ({{ fiscalYear.base_currency }})</th>
                                <th class="border-b border-line px-2 font-medium">Approved by, in order</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(limit, i) in approvals.defaults" :key="i">
                                <td class="border-b border-line px-2 py-1.5">{{ limit.label }}</td>
                                <td class="border-b border-line px-2 py-1.5 tabular-nums">{{ limit.amount }}</td>
                                <td class="border-b border-line px-2 py-1.5">{{ limit.approvers }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="approvals.existing > 0" class="text-ui">{{ approvals.existing }} approval limits are already set; a suggestion that overlaps one is left out.</p>
                <p v-if="errors.form" class="text-ui text-danger" role="alert">{{ errors.form }}</p>
                <div class="flex gap-2">
                    <Button @click="router.post('/setup/approvals')">Use these limits</Button>
                    <Button variant="ghost" @click="skip">Skip for now</Button>
                </div>
            </div>

            <div v-else class="grid max-w-[560px] gap-4">
                <h2 class="text-section font-semibold">{{ finished ? 'Setup is finished' : 'Ready to start' }}</h2>
                <ul class="grid gap-1 text-body">
                    <li v-for="s in steps.slice(0, -1)" :key="s.id" class="flex items-center gap-2">
                        <span class="size-2 rounded-full" :class="s.done ? 'bg-ok' : 'bg-warn'" aria-hidden="true" />{{ s.label }}<span class="text-ink-2">— {{ s.done ? 'saved' : s.allowed ? 'not saved yet' : `left for the ${s.owner}` }}</span>
                    </li>
                </ul>
                <p class="text-body">Next: issue a policy, take the payment and watch the accounting happen. The guided tour on Home walks you through a week in a non-life insurer.</p>
                <div><Button data-tour="setup-finish" @click="router.post('/setup/finish')">{{ finished ? 'Back to Home' : 'Finish setup' }}</Button></div>
            </div>

            <template #summary>
                <dl class="grid gap-2 text-ui">
                    <div><dt class="text-dense text-ink-2">Company</dt><dd>{{ props.company.name || '—' }}</dd><dd class="text-dense text-ink-2">{{ props.company.branches.map((b) => b.name).join(', ') || 'No branches yet' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Fiscal year</dt><dd>{{ fiscalYear.opened ? `From ${monthLabel(fiscalYear.first_month)}` : 'Not opened yet' }}</dd><dd class="text-dense text-ink-2">{{ fiscalYear.base_currency }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Chart of accounts</dt><dd>{{ chartOfAccounts.imported !== null ? `${chartOfAccounts.imported} accounts` : 'Not created yet' }}</dd><dd v-if="chartOfAccounts.imported !== null"><Link href="/accounting/chart-of-accounts" class="text-dense text-accent-text hover:underline">Open the chart of accounts</Link></dd></div>
                    <div><dt class="text-dense text-ink-2">Products</dt><dd>{{ props.product.existing.map((p) => p.name).join(', ') || 'None yet' }}</dd></div>
                    <div><dt class="text-dense text-ink-2">Users</dt><dd>{{ users.existing.length }}</dd></div>
                    <div v-if="product.effective_from && current === 'product'"><dt class="text-dense text-ink-2">Sold from</dt><dd>{{ formatDate(product.effective_from) }}</dd></div>
                </dl>
            </template>
        </Stepper>
    </AppLayout>
</template>
