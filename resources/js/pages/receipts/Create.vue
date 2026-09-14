<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import LookupInput from '@/components/forms/LookupInput.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, parseMoney } from '@/lib/money';
import { type PreviewResult, previewJournal } from '@/lib/preview';
import { initialReceipt, receiptAllocates, receiptPayload, type ReceiptDefaults, type ReceiptPrefill } from '@/lib/receiptForm';

const props = defineProps<{
    entity: { code: string; currency: string };
    channels: string[];
    branches: { id: string; code: string; name: string }[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    agents: { id: string; code: string }[];
    installments: { id: string; label: string; outstanding: string }[];
    /** Flow fix X1: filled in from the policy the receipt was opened from (/receipts/create?policy=…). */
    prefill: ReceiptPrefill | null;
    defaults: ReceiptDefaults;
    /** GA-03 (D-65): branches where this user allocates; elsewhere the money is held in suspense, noted for the policy, until a branch manager allocates it. */
    allocateBranchIds?: string[];
    /** GA-07: the bank statement line the receipt is recorded for (Home "Receipts to record"). */
    statementLine?: { amount: string; value_date: string; reference: string | null; bank_account_id: string } | null;
}>();

const form = useForm({
    ...initialReceipt(props.prefill, props.defaults, props.branches), reference: props.statementLine?.reference ?? '', bank_account_id: props.statementLine?.bank_account_id ?? '',
    ...(props.statementLine ? { amount: props.statementLine.amount, value_date: props.statementLine.value_date, channel: 'bank_transfer' } : {}),
    cheque_no: '', cheque_bank: '', cheque_date: '', collected_by_agent_id: '',
});
const dateErrors = ref<Record<string, string | null>>({});
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());

const allocated = computed(() => form.allocations.reduce((sum, line) => sum + (parseMoney(line.amount) ?? 0n), 0n));
const received = computed(() => parseMoney(form.amount));
const remaining = computed(() => (received.value === null ? null : received.value - allocated.value));

const preview = ref<PreviewResult | null>(null);
const previewOpen = ref(false);
const cancelHref = props.prefill ? `/policies/${props.prefill.policy.id}` : '/receipts';
const canAllocate = computed(() => receiptAllocates(props.allocateBranchIds, form.branch_id));
const payload = () => receiptPayload(form.data(), canAllocate.value, props.prefill?.policy.id ?? null);

async function review(): Promise<void> {
    form.clearErrors();
    const outcome = await previewJournal('/receipts', payload());
    if (!outcome.ok) {
        form.setError(outcome.errors as never);
        return;
    }
    preview.value = outcome.result;
    previewOpen.value = true;
}

function post(): void {
    form.transform(() => payload()).post('/receipts', { onFinish: () => (previewOpen.value = false) });
}
</script>

<template>
    <AppLayout help="receipts" title="Record a receipt">
        <h1 class="text-title font-semibold">Record a receipt</h1>
        <p v-if="prefill && !canAllocate" class="mb-5 text-ui text-ink-2">The premium outstanding on {{ prefill.policy.number }}. Change the amount if the customer paid less. The money is held in suspense for {{ prefill.policy.number }}; your branch manager allocates it to the installments.</p>
        <p v-else-if="prefill" class="mb-5 text-ui text-ink-2">The premium outstanding on {{ prefill.policy.number }}, allocated to its unpaid installments. Change the amount if the customer paid less; anything left over is held in suspense.</p>
        <p v-else-if="!canAllocate" class="mb-5 text-ui text-ink-2">The money is held in suspense until your branch manager allocates it to installments.</p>
        <p v-else class="mb-5 text-ui text-ink-2">Allocate the money to installments now; anything left over is held in suspense.</p>
        <FormLayout data-tour="receipt-form" submit-label="Review and post" :cancel-href="cancelHref":dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="review">
            <Field id="amount" :label="`Amount received (${entity.currency})`" :error="form.errors.amount" hint="↑ and ↓ add or take away 1,000.">
                <MoneyInput v-model="form.amount" />
            </Field>
            <Field id="value_date" label="Value date" :error="dateErrors.value_date ?? form.errors.value_date" hint="t for today, -1 for yesterday.">
                <DateInput v-model="form.value_date" @invalid="dateErrors.value_date = $event" />
            </Field>
            <!-- GA-38: who paid; the policyholder when the receipt starts from a policy. -->
            <Field id="party_id" label="Received from" optional hint="Name, mobile or tax ID. Left empty, the policyholder of the installments paid." :error="form.errors.party_id">
                <LookupInput id="party_id" v-model="form.party_id" type="customer" :initial="prefill?.payer ?? null" placeholder="Payer name or mobile" />
            </Field>
            <Field id="channel" label="Received by" :error="form.errors.channel">
                <SelectInput v-model="form.channel" :options="channels.map((c) => ({ value: c, label: words(c) }))" />
            </Field>
            <template v-if="form.channel === 'cheque'">
                <Field id="cheque_no" label="Cheque number" :error="form.errors.cheque_no">
                    <input id="cheque_no" v-model="form.cheque_no" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body tabular-nums" />
                </Field>
                <Field id="cheque_bank" label="Drawee bank" :error="form.errors.cheque_bank">
                    <input id="cheque_bank" v-model="form.cheque_bank" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body" />
                </Field>
                <Field id="cheque_date" label="Cheque date" :error="dateErrors.cheque_date ?? form.errors.cheque_date">
                    <DateInput v-model="form.cheque_date" @invalid="dateErrors.cheque_date = $event" />
                </Field>
            </template>
            <Field id="collected_by_agent_id" label="Collected by agent" optional :hint="form.channel === 'cash' ? 'The agent owes the cash until it is deposited, so it must be allocated in full.' : 'Recorded for the agent; the money reaches the company\'s bank.'" :error="form.errors.collected_by_agent_id">
                <LookupInput v-model="form.collected_by_agent_id" type="agent" placeholder="Producer code or name" />
            </Field>
            <Field id="reference" label="Reference" optional :error="form.errors.reference" hint="The payer's reference or transaction number, as on the statement.">
                <input id="reference" v-model="form.reference" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body" />
            </Field>
            <Field id="branch_id" label="Branch" :error="form.errors.branch_id">
                <SelectInput v-model="form.branch_id" :options="branches.map((b) => ({ value: b.id, label: b.name }))" />
            </Field>
            <Field id="bank_account_id" label="Bank account" optional :error="form.errors.bank_account_id">
                <SelectInput v-model="form.bank_account_id" placeholder="Default bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" />
            </Field>

            <fieldset v-if="canAllocate" class="grid gap-2">
                <legend class="mb-1 text-ui font-medium">Allocate to installments</legend>
                <div v-for="(line, index) in form.allocations" :key="index" class="grid grid-cols-[minmax(0,1fr)_140px_32px] items-start gap-2">
                    <div>
                        <LookupInput :id="`allocation-${index}`" v-model="line.installment_id" type="installment" :initial="line.label ? { id: line.installment_id, label: line.label } : null"placeholder="Policy number or payer" @selected="(r) => { line.outstanding = r?.amount; if (r?.amount && !line.amount) line.amount = r.amount; }" />
                        <p v-if="form.errors[`allocations.${index}.installment_id` as never]" class="text-dense text-danger" role="alert">{{ form.errors[`allocations.${index}.installment_id` as never] }}</p>
                    </div>
                    <div>
                        <MoneyInput :id="`allocation-amount-${index}`" v-model="line.amount" :aria-label="`Amount for allocation ${index + 1}`" />
                        <p v-if="line.outstanding" class="text-right text-dense text-ink-2 tabular-nums">of {{ line.outstanding }}</p>
                    </div>
                    <button type="button" class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :aria-label="`Remove allocation ${index + 1}`" @click="form.allocations.splice(index, 1)">
                        <X :size="16" :stroke-width="1.5" />
                    </button>
                </div>
                <button type="button" class="inline-flex h-8 items-center gap-1.5 justify-self-start rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" @click="form.allocations.push({ installment_id: '', amount: '' })">
                    <Plus :size="16" :stroke-width="1.5" />Add an installment
                </button>
                <dl v-if="received !== null" class="grid grid-cols-[1fr_auto] gap-x-4 border-t border-line pt-2 text-ui tabular-nums">
                    <dt class="text-ink-2">Allocated</dt><dd class="text-right">{{ formatMinor(allocated) }}</dd>
                    <dt class="text-ink-2">Held in suspense</dt>
                    <dd class="text-right font-medium" :class="remaining !== null && remaining < 0n ? 'text-danger' : ''">{{ remaining === null ? '' : formatMinor(remaining) }}</dd>
                </dl>
                <p v-if="remaining !== null && remaining < 0n" class="text-dense text-danger" role="alert">Allocations exceed the amount received by {{ formatMinor(-remaining) }}.</p>
            </fieldset>
        </FormLayout>

        <JournalPreviewDialog
            v-model:open="previewOpen"
            :result="preview"
            :currency="entity.currency"
            title="Post this receipt?"
            :confirm-label="`Post receipt of ${form.amount} ${entity.currency}`"
            :processing="form.processing"
            @confirm="post"
        />
    </AppLayout>
</template>
