<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    entity: { code: string; currency: string };
    kinds: string[];
    accounts: { id: string; code: string; name: string; is_control: boolean }[];
    branches: { id: string; code: string; name: string }[];
}>();

type Line = { account_id: string; side: string; amount: string; branch_id: string; memo: string };
const blank = (side: string): Line => ({ account_id: '', side, amount: '', branch_id: props.branches[0]?.id ?? '', memo: '' });
const form = useForm({ transaction_date: '', description: '', kind: 'manual', reason: '', lines: [blank('debit'), blank('credit')] });
const accountOptions = props.accounts.map((a) => ({ value: a.id, label: `${a.code} · ${a.name}${a.is_control ? ' (control)' : ''}` }));
const errorFor = (index: number, field: string): string | undefined => (form.errors as Record<string, string>)[`lines.${index}.${field}`];
const hint = computed(() => (form.kind === 'adjustment' ? 'Adjustments to control accounts need a reason and accounting.post_to_control.' : 'Control accounts accept adjustments only.'));
</script>

<template>
    <AppLayout title="New manual journal">
        <PageHeader :eyebrow="`${entity.code} · ${entity.currency}`" title="New manual journal" description="Submitted for approval when saved. Someone other than you must approve it." />
        <Card class="max-w-5xl">
            <FormBanner />
            <form class="grid gap-4" @submit.prevent="form.post('/accounting/journals')">
                <div class="grid gap-4 sm:grid-cols-4">
                    <Field id="transaction_date" label="Date" :error="form.errors.transaction_date"><Input id="transaction_date" v-model="form.transaction_date" type="date" /></Field>
                    <Field id="kind" label="Kind" :hint="hint" :error="form.errors.kind"><SelectInput id="kind" v-model="form.kind" :options="kinds.map((k) => ({ value: k, label: k }))" /></Field>
                    <Field id="description" label="Description" :error="form.errors.description"><Input id="description" v-model="form.description" /></Field>
                    <Field id="reason" label="Reason" :error="form.errors.reason"><Input id="reason" v-model="form.reason" /></Field>
                </div>
                <div class="grid gap-2">
                    <div v-for="(line, index) in form.lines" :key="index" class="grid gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-4"><SelectInput v-model="line.account_id" placeholder="Account" :options="accountOptions" :aria-label="`Account ${index + 1}`" /><p v-if="errorFor(index, 'account_id')" class="text-xs text-brick-soft">{{ errorFor(index, 'account_id') }}</p></div>
                        <div class="sm:col-span-2"><SelectInput v-model="line.side" :options="[{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }]" :aria-label="`Side ${index + 1}`" /></div>
                        <div class="sm:col-span-2"><Input v-model="line.amount" inputmode="decimal" placeholder="Amount" :aria-label="`Amount ${index + 1}`" /><p v-if="errorFor(index, 'amount')" class="text-xs text-brick-soft">{{ errorFor(index, 'amount') }}</p></div>
                        <div class="sm:col-span-2"><SelectInput v-model="line.branch_id" placeholder="No branch" :options="branches.map((b) => ({ value: b.id, label: b.code }))" :aria-label="`Branch ${index + 1}`" /></div>
                        <div class="flex gap-1 sm:col-span-2"><Input v-model="line.memo" placeholder="Memo" :aria-label="`Memo ${index + 1}`" /><Button v-if="form.lines.length > 2" variant="ghost" @click="form.lines.splice(index, 1)">×</Button></div>
                    </div>
                    <Button variant="ghost" class="justify-self-start" @click="form.lines.push(blank('debit'))">Add line</Button>
                </div>
                <Button type="submit" :disabled="form.processing" class="justify-self-start">Save and submit</Button>
            </form>
        </Card>
    </AppLayout>
</template>
