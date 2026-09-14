<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, parseMoney } from '@/lib/money';

/** Manual journal: header fields in one column, then the lines with a live debit/credit check. Saving submits it for someone else's approval. */
const props = defineProps<{
    entity: { code: string; currency: string };
    kinds: string[];
    accounts: { id: string; code: string; name: string; is_control: boolean }[];
    branches: { id: string; code: string; name: string }[];
    today: string;
}>();

type Line = { account_id: string; side: string; amount: string; branch_id: string; memo: string };
const blank = (side: string): Line => ({ account_id: '', side, amount: '', branch_id: props.branches[0]?.id ?? '', memo: '' });
const form = useForm({ transaction_date: props.today, description: '', kind: 'manual', reason: '', lines: [blank('debit'), blank('credit')] });
const accountOptions = props.accounts.map((a) => ({ value: a.id, label: `${a.code} ${a.name}${a.is_control ? ' (control account)' : ''}` }));
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
                <div class="grid grid-cols-[minmax(0,1fr)_104px_140px_110px_160px_28px] gap-2 text-dense text-ink-2" aria-hidden="true">
                    <span>Account</span><span>Side</span><span class="text-right">Amount ({{ entity.currency }})</span><span>Branch</span><span>Memo</span><span />
                </div>
                <div v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-[minmax(0,1fr)_104px_140px_110px_160px_28px] items-start gap-2">
                    <div><SelectInput v-model="line.account_id" placeholder="Choose an account" :options="accountOptions" :aria-label="`Account, line ${index + 1}`" /><p v-if="errorFor(index, 'account_id')" class="text-dense text-danger" role="alert">{{ errorFor(index, 'account_id') }}</p></div>
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
    </AppLayout>
</template>
