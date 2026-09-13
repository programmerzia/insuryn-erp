<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, TimelineEntry } from '@/components/object/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';

const props = defineProps<{
    receipt: { id: string; number: string; channel: string; amount: string; value_date: string; reference: string | null; status: string; cheque_no: string | null; cheque_bank: string | null; bounced_on: string | null; bounce_reason: string | null };
    allocations: { id: string; policy_number: string | null; amount: string; posted_on: string; reversed_on: string | null }[];
    suspense: { id: string; amount: string; open: string; status: string } | null;
    actions: { bounce: boolean };
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
}>();

const bouncing = ref(false);
const bounce = useMoneyForm(() => `/receipts/${props.receipt.id}/bounce`, { bounced_on: '', reason: '' }, () => (bouncing.value = false));
const words = (v: string) => v.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const facts = computed(() => [
    { label: 'Amount (BDT)', value: formatMoney(props.receipt.amount), num: true },
    { label: 'Value date', value: formatDate(props.receipt.value_date) },
    { label: 'In suspense', value: props.suspense ? formatMoney(props.suspense.open) : '0.00', num: true },
    { label: 'Received by', value: words(props.receipt.channel) },
]);
</script>

<template>
    <AppLayout :title="receipt.number">
        <ObjectPage
            :title="receipt.number"
            :subtitle="[receipt.reference, receipt.cheque_no ? `cheque ${receipt.cheque_no} ${receipt.cheque_bank}` : null, receipt.bounced_on ? `bounced ${formatDate(receipt.bounced_on)}: ${receipt.bounce_reason}` : null].filter(Boolean).join(' · ')"
            :status="receipt.status"
            :facts="facts"
            :crumbs="[{ label: 'Receipts', href: '/receipts' }]"
            currency="BDT"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
        >
            <template #actions>
                <button v-if="actions.bounce" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="bouncing = true">Cheque bounced</button>
                <Link v-if="suspense && suspense.status === 'open'" :href="`/receipts/${receipt.id}/allocate`" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Allocate {{ formatMoney(suspense.open) }}</Link>
            </template>
            <template #overview>
                <h2 class="mb-2 text-ui font-medium">Allocations</h2>
                <div class="max-w-[760px] overflow-x-auto border border-line">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                        <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">Policy</th><th class="w-32 border-b border-line px-3 text-left font-medium">Posted</th><th class="w-36 border-b border-line px-3 text-right font-medium">Amount (BDT)</th><th class="w-32 border-b border-line px-3 text-left font-medium">Reversed</th></tr></thead>
                        <tbody>
                            <tr v-for="a in allocations" :key="a.id" class="h-(--row-h)"><td class="border-b border-line px-3">{{ a.policy_number }}</td><td class="border-b border-line px-3">{{ formatDate(a.posted_on) }}</td><td class="num border-b border-line px-3">{{ formatMoney(a.amount) }}</td><td class="border-b border-line px-3 text-danger">{{ formatDate(a.reversed_on) }}</td></tr>
                            <tr v-if="allocations.length === 0"><td colspan="4" class="px-3 py-6 text-center text-ui text-ink-2">Not allocated yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </ObjectPage>
        <Drawer v-model:open="bouncing" title="Record a bounced cheque">
            <FormLayout submit-label="Review the reversal" :dirty="bounce.form.isDirty" :processing="bounce.form.processing" :error="(bounce.form.errors as Record<string, string>).form" @submit="bounce.review" @cancel="bouncing = false">
                <p class="text-ui text-ink-2">Every allocation is reversed, the installments become unpaid again and the money leaves the bank account.</p>
                <Field id="bounced_on" label="Bounced on" :error="bounce.form.errors.bounced_on"><DateInput v-model="bounce.form.bounced_on" /></Field>
                <Field id="bounce_reason" label="Bank's reason" :error="bounce.form.errors.reason"><TextInput v-model="bounce.form.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="bounce.previewOpen.value" :result="bounce.preview.value" :title="`Reverse ${receipt.number}?`" confirm-label="Reverse the receipt" currency="BDT" :processing="bounce.form.processing" @confirm="bounce.post" />
    </AppLayout>
</template>
