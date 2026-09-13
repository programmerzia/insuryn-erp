<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/** Phase 3 design §6 "Cover notes queue (expiring)" (slice R6): active cover notes by their last day, soonest first; cancel with a reason. */
interface CoverNoteRow {
    id: string; number: string; status: string; valid_from: string; valid_to: string; days_left: number | null; proposal_id: string; proposal_number: string;
    customer: string; product: string; issued_by: string; cancel_reason: string | null; can_cancel: boolean;
    /** Printed versions of the cover note, newest first. */
    documents: { id: string; version: number; locale: string; rendered_at: string; url: string }[];
}
const props = defineProps<{ coverNotes: CoverNoteRow[]; today: string; within: number | null; expiringDays: number }>();

const active = ref<string | null>(null);
const cancelling = ref<CoverNoteRow | null>(null);
const form = useForm({ reason: '' });
const errors = computed(() => form.errors as Record<string, string>);
const left = (n: CoverNoteRow) => (n.days_left === null ? '' : n.days_left < 0 ? 'Ended' : n.days_left === 0 ? 'Ends today' : `${n.days_left} days`);
function filter(days: number | null): void {
    router.get('/cover-notes', days === null ? {} : { within: days }, { preserveState: true, preserveScroll: true });
}
const printing = useForm({ locale: 'en' });
function print(note: CoverNoteRow, locale: 'en' | 'bn'): void {
    printing.locale = locale;
    printing.post(`/cover-notes/${note.id}/generated-documents`, { preserveScroll: true });
}
function cancel(): void {
    if (cancelling.value) form.post(`/cover-notes/${cancelling.value.id}/cancel`, { preserveScroll: true, onSuccess: () => (cancelling.value = null) });
}
const columns: DataColumn<CoverNoteRow>[] = [
    { id: 'number', header: 'Cover note', value: (n) => n.number, width: 170 },
    { id: 'customer', header: 'Customer', value: (n) => n.customer, width: 200 },
    { id: 'proposal', header: 'Proposal', value: (n) => n.proposal_number, href: (n) => `/proposals/${n.proposal_id}`, width: 170 },
    { id: 'product', header: 'Product', value: (n) => n.product, width: 110 },
    { id: 'from', header: 'From', type: 'date', value: (n) => n.valid_from, width: 120 },
    { id: 'to', header: 'Until', type: 'date', value: (n) => n.valid_to, width: 120 },
    { id: 'left', header: 'Left', value: (n) => left(n), width: 100 },
    { id: 'status', header: 'Status', type: 'status', value: (n) => n.status, filterOptions: ['active', 'expired', 'superseded', 'cancelled'] },
];
</script>

<template>
    <AppLayout help="policies" title="Cover notes" fill>
        <QueueView
            id="cover-notes"
            v-model:active="active"
            title="Cover notes"
            :columns="columns"
            :rows="coverNotes"
            :row-key="(n) => n.id"
            :empty-text="within === null ? 'No cover notes yet.' : `No cover notes end in the next ${within} days.`"
            :empty-action="within === null ? { label: 'Open quotes', href: '/quotations' } : { label: 'Show all cover notes', href: '/cover-notes' }"
            :inspector-title="(n) => n.number"
            :inspector-subtitle="(n) => n.customer"
        >
            <template #toolbar>
                <div class="ml-2 flex rounded-control border border-line text-ui" role="group" aria-label="Which cover notes">
                    <button type="button" class="px-2 py-1" :class="within === null ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="filter(null)">All</button>
                    <button type="button" class="px-2 py-1" :class="within !== null ? 'bg-accent-soft text-ink' : 'text-ink-2'" @click="filter(expiringDays)">Ending within {{ expiringDays }} days</button>
                </div>
            </template>
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Status' },
                        { label: 'Cover', value: `${formatDate(row.valid_from)} to ${formatDate(row.valid_to)}` },
                        { label: 'Left', value: left(row) },
                        { label: 'Product', value: row.product },
                        { label: 'Issued by', value: row.issued_by },
                        { label: 'Cancelled because', value: row.cancel_reason },
                    ]"
                >
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Link :href="`/proposals/${row.proposal_id}`" class="text-ui text-accent-text hover:underline">Open proposal {{ row.proposal_number }}</Link>
                    <Button v-if="row.can_cancel" variant="ghost" @click="cancelling = row; form.reset(); form.clearErrors()">Cancel cover note</Button>
                </div>
                <div class="mt-4 grid gap-2 border-t border-line pt-4 text-ui" aria-label="Printed cover note">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">Print</span>
                        <Button variant="secondary" size="sm" :disabled="printing.processing" @click="print(row, 'en')">English</Button>
                        <Button variant="secondary" size="sm" :disabled="printing.processing" @click="print(row, 'bn')"><span lang="bn">বাংলা</span></Button>
                    </div>
                    <a v-for="d in row.documents" :key="d.id" :href="d.url" class="text-accent-text hover:underline">Version {{ d.version }} · {{ d.locale === 'bn' ? 'বাংলা' : 'English' }} · {{ formatDate(d.rendered_at) }}</a>
                </div>
            </template>
        </QueueView>

        <Drawer :open="cancelling !== null" :title="`Cancel ${cancelling?.number ?? ''}`" @update:open="(o) => !o && (cancelling = null)">
            <p class="mb-4 text-ui text-ink-2">The cover note stops being evidence of cover now. Say why.</p>
            <FormLayout submit-label="Cancel cover note" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="cancel" @cancel="cancelling = null">
                <Field id="cancel_reason" label="Reason" :error="errors.reason"><TextInput id="cancel_reason" v-model="form.reason" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
