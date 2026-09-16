<script setup lang="ts">
import { useEntityCurrency } from '@/lib/entityCurrency';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
const currency = useEntityCurrency();

/**
 * Phase 3 design §6 "Expiry register queue" (slice R9): policies coming up for renewal, soonest first, by bucket, branch, producer and status. The inspector
 * shows the policy, its renewal quotation and the notices sent; it offers a renewal quotation now or records why the policy is not renewed.
 */
interface Entry {
    id: string; policy_id: string; policy_number: string; policy_status: string; customer: string; product: string; branch: string; producer: string | null;
    expiry: string; days_left: number; bucket: number | null; status: string; rated: boolean; premium: string;
    quotation: { id: string; number: string; status: string; premium: string; valid_until: string } | null;
    quote_problem: string | null; renewal_policy: { id: string; number: string } | null; reason: string | null; reason_note: string | null;
    notices: { id: string; kind: string; offset_days: number; sent_on: string; document_url: string | null }[];
    can_offer: boolean; can_record: boolean;
}
const props = defineProps<{
    entries: Entry[]; entriesTotal?: number; today: string; buckets: number[]; quoteDaysBefore: number;
    filters: { bucket: number | null; status: string | null; branch: string | null; producer: string | null };
    reasons: { value: string; label: string }[]; branches: { id: string; code: string }[]; producers: { id: string; label: string }[];
}>();

const active = ref<string | null>(null);
const recording = ref<Entry | null>(null);
const form = useForm({ reason: '', note: '' });
const errors = computed(() => form.errors as Record<string, string>);
const offering = useForm({});
const statuses = ['upcoming', 'renewal_offered', 'renewed', 'lapsed', 'not_renewed'];
/** GA-37: "lapsed" is kept for a policy lapsed for non-payment; a register row that expired with no renewal reads "Expired, not renewed". */
const statusLabel = (s: string) => (s === 'lapsed' ? 'Expired, not renewed' : s === 'not_renewed' ? 'Not renewed' : s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase()));
const filtered = computed(() => Object.values(props.filters).some((v) => v !== null));
const left = (e: Entry) => (e.days_left < 0 ? 'Expired' : e.days_left === 0 ? 'Expires today' : `${e.days_left} days`);

function filter(changes: Partial<typeof props.filters>): void {
    const query = Object.fromEntries(Object.entries({ ...props.filters, ...changes }).filter(([, v]) => v !== null && v !== ''));
    router.get('/renewals', query, { preserveState: true, preserveScroll: true });
}
function offer(entry: Entry): void {
    offering.post(`/renewals/${entry.id}/quote`, { preserveScroll: true });
}
function record(): void {
    if (recording.value) form.post(`/renewals/${recording.value.id}/not-renewed`, { preserveScroll: true, onSuccess: () => (recording.value = null) });
}
const columns: DataColumn<Entry>[] = [
    { id: 'policy', header: 'Policy', value: (e) => e.policy_number, href: (e) => `/policies/${e.policy_id}`, width: 180 },
    { id: 'customer', header: 'Customer', value: (e) => e.customer, width: 190 },
    { id: 'product', header: 'Product', value: (e) => e.product, width: 110 },
    { id: 'branch', header: 'Branch', value: (e) => e.branch, width: 90, filterOptions: props.branches.map((b) => b.code) },
    { id: 'producer', header: 'Producer', value: (e) => e.producer ?? 'Direct', width: 170, muted: true },
    { id: 'expiry', header: 'Expires', type: 'date', value: (e) => e.expiry, width: 120 },
    { id: 'left', header: 'Left', value: (e) => left(e), width: 100 },
    { id: 'premium', header: 'Premium', type: 'money', value: (e) => e.premium, width: 130 },
    { id: 'quotation', header: 'Renewal quotation', value: (e) => e.quotation?.number ?? '', href: (e) => (e.quotation ? `/quotations/${e.quotation.id}` : null), width: 180 },
    { id: 'status', header: 'Status', type: 'status', value: (e) => (e.status === 'lapsed' ? 'expired_not_renewed' : e.status), filterOptions: statuses.map((s) => (s === 'lapsed' ? 'expired_not_renewed' : s)) },
];
</script>

<template>
    <AppLayout help="renewals" title="Renewals" fill>
        <QueueView
            id="renewals"
            v-model:active="active"
            title="Renewals"
            :columns="columns"
            :rows="entries"
            :total="entriesTotal ?? null"
            :row-key="(e) => e.id"
            :currency="currency"
            :empty-text="filtered ? 'No policies match these filters.' : `No policies expire in the next ${buckets[buckets.length - 1]} days.`"
            :empty-action="filtered ? { label: 'Show all renewals', href: '/renewals' } : { label: 'Open policies', href: '/policies' }"
            :inspector-title="(e) => e.policy_number"
            :inspector-subtitle="(e) => e.customer"
        >
            <template #toolbar>
                <div class="ml-2 flex rounded-control border border-line text-ui" role="group" aria-label="Expiring within">
                    <button type="button" class="px-2 py-1" :class="filters.bucket === null ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="filter({ bucket: null })">All</button>
                    <button v-for="b in buckets" :key="b" type="button" class="px-2 py-1" :class="filters.bucket === b ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="filter({ bucket: b })">{{ b }} days</button>
                </div>
                <label class="sr-only" for="renewals-status">Status</label>
                <select id="renewals-status" class="ml-2 h-8 rounded-control border border-line-control bg-surface px-2 text-body text-ink" :value="filters.status ?? ''" @change="filter({ status: ($event.target as HTMLSelectElement).value || null })">
                    <option value="">Every status</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                </select>
                <label class="sr-only" for="renewals-branch">Branch</label>
                <select id="renewals-branch" class="ml-2 h-8 rounded-control border border-line-control bg-surface px-2 text-body text-ink" :value="filters.branch ?? ''" @change="filter({ branch: ($event.target as HTMLSelectElement).value || null })">
                    <option value="">Every branch</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.code }}</option>
                </select>
                <label class="sr-only" for="renewals-producer">Producer</label>
                <select id="renewals-producer" class="ml-2 h-8 max-w-48 rounded-control border border-line-control bg-surface px-2 text-body text-ink" :value="filters.producer ?? ''" @change="filter({ producer: ($event.target as HTMLSelectElement).value || null })">
                    <option value="">Every producer</option>
                    <option v-for="p in producers" :key="p.id" :value="p.id">{{ p.label }}</option>
                </select>
            </template>
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Status' },
                        { label: 'Expires', value: `${formatDate(row.expiry)} (${left(row)})` },
                        { label: 'Bucket', value: row.bucket === null ? null : `${row.bucket} days` },
                        { label: 'Product', value: row.product },
                        { label: 'Branch', value: row.branch },
                        { label: 'Producer', value: row.producer ?? 'Direct' },
                        { label: 'Current premium', value: row.premium },
                        { label: 'Renewal quotation', value: row.quotation ? `${row.quotation.number} · ${row.quotation.premium} · valid until ${formatDate(row.quotation.valid_until)} (${row.quotation.status})` : null },
                        { label: 'Renewal policy', value: row.renewal_policy?.number ?? null },
                        { label: 'Why not renewed', value: row.reason === null ? null : row.reason_note ? `${row.reason}: ${row.reason_note}` : row.reason },
                        { label: 'Could not quote', value: row.quote_problem },
                    ]"
                >
                    <template #Status><StatusBadge :status="row.status === 'lapsed' ? 'expired_not_renewed' : row.status" /></template>
                </DetailList>
                <p v-if="!row.rated && row.status === 'upcoming'" class="mt-3 text-ui text-ink-2">This product has no rating plan, so no renewal quotation is offered automatically. Renew it from the policy page.</p>
                <p v-else-if="row.status === 'upcoming' && row.days_left > quoteDaysBefore" class="mt-3 text-ui text-ink-2">The renewal quotation is offered {{ quoteDaysBefore }} days before expiry.</p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Button v-if="row.can_offer" :disabled="offering.processing" @click="offer(row)">Create renewal quote now</Button>
                    <Link :href="`/policies/${row.policy_id}`" class="text-ui text-accent-text hover:underline">Open policy</Link>
                    <Link v-if="row.quotation" :href="`/quotations/${row.quotation.id}`" class="text-ui text-accent-text hover:underline">Open renewal quotation</Link>
                    <Link v-if="row.renewal_policy" :href="`/policies/${row.renewal_policy.id}`" class="text-ui text-accent-text hover:underline">Open renewal policy</Link>
                    <Button v-if="row.can_record" variant="ghost" @click="recording = row; form.reset(); form.clearErrors()">Record not renewed</Button>
                </div>
                <div class="mt-4 grid gap-1 border-t border-line pt-4 text-ui" aria-label="Notices sent">
                    <span class="font-medium">Notices sent</span>
                    <span v-if="row.notices.length === 0" class="text-ink-2">None yet.</span>
                    <template v-for="n in row.notices" :key="n.id">
                        <a v-if="n.document_url" :href="n.document_url" class="text-accent-text hover:underline">{{ n.kind === 'notice' ? 'Renewal notice' : 'Reminder' }} · {{ n.offset_days }} days before · {{ formatDate(n.sent_on) }}</a>
                        <span v-else>{{ n.kind === 'notice' ? 'Renewal notice' : 'Reminder' }} · {{ n.offset_days }} days before · {{ formatDate(n.sent_on) }}</span>
                    </template>
                </div>
            </template>
        </QueueView>

        <Drawer :open="recording !== null" :title="`Not renewed: ${recording?.policy_number ?? ''}`" @update:open="(o) => !o && (recording = null)">
            <p class="mb-4 text-ui text-ink-2">The policy leaves the renewal queue and its open renewal quotation is declined. Say why the customer is not renewing.</p>
            <FormLayout submit-label="Record not renewed" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="record" @cancel="recording = null">
                <Field id="not_renewed_reason" label="Reason" :error="errors.reason"><SelectInput id="not_renewed_reason" v-model="form.reason" :options="reasons" placeholder="Choose a reason" /></Field>
                <Field id="not_renewed_note" label="Note" :optional="form.reason !== 'other'" :error="errors.note"><TextInput id="not_renewed_note" v-model="form.note" :maxlength="1000" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
