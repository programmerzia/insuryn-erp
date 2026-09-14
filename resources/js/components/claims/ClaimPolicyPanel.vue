<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import { formatDate, formatMoney } from '@/lib/format';

/**
 * Gap fix GA-12: the policy as the claims desk checks it before reserving ("no premium, no cover") — cover, sum insured, premium paid and owed, cheques
 * that bounced and the policy's other claims. Read-only; the policy number links to the policy only for people who may open it.
 */
export interface ClaimPolicyFacts {
    number: string | null;
    status: string;
    product: string;
    inception: string;
    expiry: string;
    sum_insured: string | null;
    gross_premium: string | null;
    paid: string | null;
    outstanding: string | null;
    unpaid_at_loss: string | null;
    bounced_cheques: { receipt_number: string; cheque_no: string | null; bounced_on: string | null; amount: string | null }[];
    other_claims: { id: string; number: string; loss_date: string; status: string; reserve: string | null }[];
    href: string | null;
}
defineProps<{ facts: ClaimPolicyFacts; currency: string }>();
</script>

<template>
    <section aria-labelledby="claim-policy-title" class="border border-line p-3">
        <h2 id="claim-policy-title" class="mb-2 flex items-center gap-2 text-ui font-medium">
            Policy
            <Link v-if="facts.href" :href="facts.href" class="text-accent-text hover:underline">{{ facts.number }}</Link>
            <span v-else>{{ facts.number }}</span>
            <StatusBadge :status="facts.status" />
        </h2>
        <p v-if="facts.unpaid_at_loss && facts.unpaid_at_loss !== '0.00'" class="mb-2 rounded-control border border-warn bg-surface-2 px-2 py-1.5 text-ui" role="note">
            Premium of {{ formatMoney(facts.unpaid_at_loss) }} {{ currency }} due by the date of loss is unpaid. Check it before reserving.
        </p>
        <p v-if="facts.bounced_cheques.length" class="mb-2 rounded-control border border-danger px-2 py-1.5 text-ui text-danger" role="note">
            {{ facts.bounced_cheques.length === 1 ? 'A premium cheque bounced' : `${facts.bounced_cheques.length} premium cheques bounced` }}:
            {{ facts.bounced_cheques.map((b) => `${b.cheque_no ? `cheque ${b.cheque_no}` : b.receipt_number} (${formatMoney(b.amount)}, ${formatDate(b.bounced_on)})`).join('; ') }}.
        </p>
        <DetailList
            :items="[
                { label: 'Product', value: facts.product },
                { label: 'Cover', value: `${formatDate(facts.inception)} to ${formatDate(facts.expiry)}` },
                { label: `Sum insured (${currency})`, value: facts.sum_insured ? formatMoney(facts.sum_insured) : 'Not recorded', num: Boolean(facts.sum_insured) },
                { label: `Premium (${currency})`, value: formatMoney(facts.gross_premium), num: true },
                { label: 'Paid', value: formatMoney(facts.paid), num: true },
                { label: 'Still owed', value: formatMoney(facts.outstanding), num: true },
            ]"
        />
        <h3 class="mt-3 mb-1 text-dense font-medium text-ink-2">Other claims on this policy</h3>
        <ul class="grid gap-1 text-ui">
            <li v-for="c in facts.other_claims" :key="c.id" class="flex items-center gap-2">
                <Link :href="`/claims/${c.id}`" class="text-accent-text hover:underline">{{ c.number }}</Link>
                <span class="text-ink-2">loss {{ formatDate(c.loss_date) }}</span>
                <StatusBadge :status="c.status" />
                <span class="ml-auto tabular-nums">{{ formatMoney(c.reserve) }}</span>
            </li>
            <li v-if="facts.other_claims.length === 0" class="text-ink-2">None.</li>
        </ul>
    </section>
</template>
