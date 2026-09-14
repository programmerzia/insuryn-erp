<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ACCOUNT_TYPES, ASK_FOR_ACCOUNT, type AccountChoice, CHART_OF_ACCOUNTS_HREF, normalSideFor, withAccount } from '@/lib/accountCreate';
import { HttpError, requestJson } from '@/lib/http';
import { formatMinor, parseMoney } from '@/lib/money';

/**
 * Manual journal: header fields in one column, then the lines with a live debit/credit check. Saving submits it for someone else's approval.
 * Flow fix X10: an account missing from the chart is created from a line in a drawer (holders of accounting.manage_coa) and chosen on that line.
 */
const props = defineProps<{
    entity: { code: string; currency: string };
    kinds: string[];
    accounts: AccountChoice[];
    branches: { id: string; code: string; name: string }[];
    today: string;
    canCreateAccount?: boolean;
}>();

type Line = { account_id: string; side: string; amount: string; branch_id: string; memo: string };
const blank = (side: string): Line => ({ account_id: '', side, amount: '', branch_id: props.branches[0]?.id ?? '', memo: '' });
const form = useForm({ transaction_date: props.today, description: '', kind: 'manual', reason: '', lines: [blank('debit'), blank('credit')] });
const accounts = ref<AccountChoice[]>(props.accounts);
const accountOptions = computed(() => accounts.value.map((a) => ({ value: a.id, label: `${a.code} ${a.name}${a.is_control ? ' (control account)' : ''}` })));

// Flow fix X10: new account for a line, through the chart-of-accounts import (one row), so the import's rules and audit apply.
const accountLine = ref<number | null>(null);
const accountDraft = ref({ code: '', name: '', type: 'expense', normal_side: 'debit', is_postable: true });
const accountErrors = ref<Record<string, string>>({});
const accountSaving = ref(false);
function newAccount(index: number): void {
    accountLine.value = index;
    accountDraft.value = { code: '', name: '', type: 'expense', normal_side: 'debit', is_postable: true };
    accountErrors.value = {};
}
function chooseType(type: string | undefined): void {
    accountDraft.value.type = type ?? 'expense';
    accountDraft.value.normal_side = normalSideFor(accountDraft.value.type);
}
function closeAccount(isOpen: boolean): void {
    if (!isOpen) accountLine.value = null;
}
async function createAccount(): Promise<void> {
    accountSaving.value = true;
    try {
        const { account } = await requestJson<{ account: AccountChoice & { is_postable: boolean } }>('POST', '/accounting/accounts', accountDraft.value);
        if (account.is_postable) {
            accounts.value = withAccount(accounts.value, account);
            const line = accountLine.value === null ? undefined : form.lines[accountLine.value];
            if (line) line.account_id = account.id;
        }
        accountLine.value = null;
    } catch (error) {
        const body = error instanceof HttpError ? (error.body as { errors?: Record<string, string[] | string>; message?: string }) : null;
        accountErrors.value = Object.fromEntries(Object.entries(body?.errors ?? { form: [body?.message ?? 'The account was not created. Try again.'] }).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : String(v)]));
    } finally {
        accountSaving.value = false;
    }
}
const errorFor = (index: number, field: string): string | undefined => (form.errors as Record<string, string>)[`lines.${index}.${field}`];
const totals = computed(() => {
    let debit = 0n;
    let credit = 0n;
    for (const line of form.lines) {
        const amount = parseMoney(line.amount) ?? 0n;
        if (line.side === 'debit') debit += amount;
        else credit += amount;
    }
    return { debit, credit, difference: debit - credit };
});
const words = (k: string) => k.replace(/^./, (c) => c.toUpperCase());
</script>

<template>
    <AppLayout title="New manual journal">
        <h1 class="text-title font-semibold">New manual journal</h1>
        <p class="mb-5 text-ui text-ink-2">Saved journals go for approval; someone other than you approves and posts them.</p>
        <FormLayout submit-label="Save and submit" cancel-href="/accounting/journals" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" wide @submit="form.post('/accounting/journals')">
            <div class="grid max-w-[560px] gap-4">
                <Field id="transaction_date" label="Date" :error="form.errors.transaction_date"><DateInput v-model="form.transaction_date" /></Field>
                <Field id="kind" label="Kind" :hint="form.kind === 'adjustment' ? 'Adjustments to control accounts need a reason and the right to post to control accounts.' : 'Control accounts accept adjustments only.'" :error="form.errors.kind">
                    <SelectInput id="kind" v-model="form.kind" :options="kinds.map((k) => ({ value: k, label: words(k) }))" />
                </Field>
                <Field id="description" label="Description" :error="form.errors.description"><TextInput v-model="form.description" /></Field>
                <Field id="reason" label="Reason" :error="form.errors.reason"><TextInput v-model="form.reason" /></Field>
            </div>
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-ui font-medium">Lines</legend>
                <p v-if="!canCreateAccount" class="text-dense text-ink-2">{{ ASK_FOR_ACCOUNT }} <Link :href="CHART_OF_ACCOUNTS_HREF" class="text-accent-text hover:underline">See the chart of accounts</Link></p>
                <div class="grid grid-cols-[minmax(0,1fr)_104px_140px_110px_160px_28px] gap-2 text-dense text-ink-2" aria-hidden="true">
                    <span>Account</span><span>Side</span><span class="text-right">Amount ({{ entity.currency }})</span><span>Branch</span><span>Memo</span><span />
                </div>
                <div v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-[minmax(0,1fr)_104px_140px_110px_160px_28px] items-start gap-2">
                    <div><SelectInput v-model="line.account_id" placeholder="Choose an account" :options="accountOptions" :aria-label="`Account, line ${index + 1}`" /><p v-if="errorFor(index, 'account_id')" class="text-dense text-danger" role="alert">{{ errorFor(index, 'account_id') }}</p><button v-if="canCreateAccount" type="button" class="mt-0.5 inline-flex items-center gap-1 text-dense text-accent-text hover:underline" @click="newAccount(index)"><Plus :size="12" :stroke-width="1.5" />New account</button></div>
                    <SelectInput v-model="line.side" :options="[{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }]" :aria-label="`Side, line ${index + 1}`" />
                    <div><MoneyInput :id="`line-amount-${index}`" v-model="line.amount" :aria-label="`Amount, line ${index + 1}`" /><p v-if="errorFor(index, 'amount')" class="text-dense text-danger" role="alert">{{ errorFor(index, 'amount') }}</p></div>
                    <SelectInput v-model="line.branch_id" placeholder="No branch" :options="branches.map((b) => ({ value: b.id, label: b.code }))" :aria-label="`Branch, line ${index + 1}`" />
                    <input v-model="line.memo" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body" placeholder="Memo" :aria-label="`Memo, line ${index + 1}`" />
                    <button v-if="form.lines.length > 2" type="button" class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2" :aria-label="`Remove line ${index + 1}`" @click="form.lines.splice(index, 1)"><X :size="14" :stroke-width="1.5" /></button>
                </div>
                <button type="button" class="inline-flex h-8 items-center gap-1.5 justify-self-start rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" @click="form.lines.push(blank(totals.difference > 0n ? 'credit' : 'debit'))"><Plus :size="16" :stroke-width="1.5" />Add a line</button>
                <dl class="grid w-80 grid-cols-[1fr_auto] gap-x-4 justify-self-end border-t border-line pt-2 text-ui tabular-nums">
                    <dt class="text-ink-2">Debits</dt><dd class="text-right">{{ formatMinor(totals.debit) }}</dd>
                    <dt class="text-ink-2">Credits</dt><dd class="text-right">{{ formatMinor(totals.credit) }}</dd>
                    <dt class="font-medium">Difference</dt><dd class="text-right font-medium" :class="totals.difference === 0n ? 'text-ok' : 'text-danger'">{{ formatMinor(totals.difference) }}</dd>
                </dl>
                <p v-if="totals.difference !== 0n && totals.debit + totals.credit > 0n" class="justify-self-end text-dense text-danger" role="alert">
                    Debits and credits differ by {{ formatMinor(totals.difference < 0n ? -totals.difference : totals.difference) }}. A journal must balance before it is saved.
                </p>
            </fieldset>
        </FormLayout>
        <Drawer :open="accountLine !== null" title="New account" @update:open="closeAccount">
            <form class="grid gap-4" novalidate @submit.prevent="createAccount">
                <p class="text-ui text-ink-2">A postable account for this line. Headings, control accounts and changes to existing accounts are in <Link :href="CHART_OF_ACCOUNTS_HREF" class="text-accent-text hover:underline">Chart of accounts</Link>.</p>
                <p v-if="accountErrors.form" class="border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ accountErrors.form }}</p>
                <div class="grid grid-cols-[120px_minmax(0,1fr)] gap-3">
                    <Field id="new-account-code" label="Code" :error="accountErrors.code"><TextInput id="new-account-code" v-model="accountDraft.code" :maxlength="32" /></Field>
                    <Field id="new-account-name" label="Name" :error="accountErrors.name"><TextInput id="new-account-name" v-model="accountDraft.name" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="new-account-type" label="Type" :error="accountErrors.type"><SelectInput id="new-account-type" :model-value="accountDraft.type" :options="ACCOUNT_TYPES" @update:model-value="chooseType" /></Field>
                    <Field id="new-account-side" label="Normal side" hint="Suggested from the type." :error="accountErrors.normal_side">
                        <SelectInput id="new-account-side" v-model="accountDraft.normal_side" :options="[{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }]" />
                    </Field>
                </div>
                <Field id="new-account-postable" label="Postable" optional hint="Journals post to postable accounts only; a heading account groups others." :error="accountErrors.is_postable">
                    <label class="flex items-center gap-2 text-ui"><input id="new-account-postable" v-model="accountDraft.is_postable" type="checkbox" class="size-3.5 accent-accent" />Journals can post to it</label>
                </Field>
                <div class="flex justify-end gap-2">
                    <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="accountLine = null">Cancel</button>
                    <button type="submit" :disabled="accountSaving" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50">Create account</button>
                </div>
            </form>
        </Drawer>
    </AppLayout>
</template>
