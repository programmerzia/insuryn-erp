<script setup lang="ts">
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AccountingJournal, AuditRow, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';

/** Design addendum v2 §B.7 asset page: Overview · Depreciation schedule · Movements · Documents · Accounting · Audit, with Move and Dispose drawers. */
type Option = { id: string; label: string };
const props = defineProps<{
    asset: { id: string; number: string; description: string; class: string; branch: string; branch_id: string; location: string | null; custodian: string | null; serial_no: string | null;
        supplier: string | null; invoice_ref: string | null; acquired_on: string; status: string; method: string; cost: string; residual: string; accumulated: string; nbv: string; source: string };
    schedule: { period: string; amount: string; accumulated: string; nbv: string; opening: boolean }[];
    movements: { moved_on: string; from: string; to: string; location: string | null; reason: string }[];
    disposal: { number: string; date: string; kind: string; proceeds: string; nbv: string; gain_loss: string; reason: string } | null;
    branches: Option[];
    bankAccounts: Option[];
    can: { manage: boolean };
    timeline?: TimelineEntry[];
    accounting?: AccountingJournal[];
    audit?: AuditRow[];
    documents?: StoredDocumentRow[];
    documentUpload: string | null;
}>();

const today = useBusinessToday();
const moving = ref(false);
const disposing = ref(false);
const move = useMoneyForm(() => `/fixed-assets/${props.asset.id}/transfer`, { to_branch_id: props.branches.find((b) => b.id !== props.asset.branch_id)?.id ?? '', moved_on: today, location: '', reason: '' }, () => (moving.value = false));
const dispose = useMoneyForm(() => `/fixed-assets/${props.asset.id}/dispose`, { kind: 'sale', disposal_date: today, proceeds: '', bank_account_id: props.bankAccounts[0]?.id ?? '', reason: '' }, () => (disposing.value = false));
const facts = computed(() => [
    { label: 'Cost (BDT)', value: formatMoney(props.asset.cost), num: true },
    { label: 'Accumulated depreciation', value: formatMoney(props.asset.accumulated), num: true },
    { label: 'Net book value', value: formatMoney(props.asset.nbv), num: true },
    { label: 'Class', value: props.asset.class },
    { label: 'Branch', value: props.asset.branch },
]);
const details = computed(() => [
    ['Description', props.asset.description], ['Method', props.asset.method], ['Residual value', `${formatMoney(props.asset.residual)} BDT`], ['Acquired', formatDate(props.asset.acquired_on)],
    ['How acquired', props.asset.source], ['Location', props.asset.location], ['Custodian', props.asset.custodian], ['Serial number', props.asset.serial_no], ['Supplier', props.asset.supplier], ['Supplier invoice', props.asset.invoice_ref],
] as [string, string | null][]);
type ScheduleRow = (typeof props.schedule)[number];
type MovementRow = (typeof props.movements)[number];
const scheduleColumns: DataColumn<ScheduleRow>[] = [
    { id: 'period', header: 'Month', value: (r) => (r.opening ? `Brought forward ${formatDate(r.period)}` : formatMonth(r.period, 'long')), width: 220 },
    { id: 'amount', header: 'Depreciation', type: 'money', value: (r) => r.amount },
    { id: 'accumulated', header: 'Accumulated', type: 'money', value: (r) => r.accumulated },
    { id: 'nbv', header: 'Net book value', type: 'money', value: (r) => r.nbv },
];
const movementColumns: DataColumn<MovementRow>[] = [
    { id: 'moved_on', header: 'Moved on', type: 'date', value: (m) => m.moved_on },
    { id: 'from', header: 'From', value: (m) => m.from, width: 90 },
    { id: 'to', header: 'To', value: (m) => m.to, width: 90 },
    { id: 'location', header: 'Location', value: (m) => m.location, width: 180 },
    { id: 'reason', header: 'Reason', value: (m) => m.reason, width: 260, muted: true },
];
</script>

<template>
    <AppLayout help="assets" :title="asset.number">
        <ObjectPage
            :title="asset.number"
            :subtitle="asset.description"
            :status="asset.status"
            :facts="facts"
            :crumbs="[{ label: 'Fixed assets', href: '/fixed-assets' }]"
            currency="BDT"
            :timeline="timeline"
            :accounting="accounting"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
            :extra-tabs="[{ value: 'schedule', label: 'Depreciation schedule' }, { value: 'movements', label: 'Movements' }]"
        >
            <template #actions>
                <button v-if="can.manage && branches.length > 1" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="moving = true">Move to another branch</button>
                <button v-if="can.manage" type="button" class="h-8 rounded-control border border-danger px-3 text-ui text-danger hover:bg-surface-2" @click="disposing = true">Dispose</button>
            </template>
            <template #overview>
                <dl class="grid max-w-[760px] grid-cols-[200px_1fr] gap-x-4 gap-y-1.5 text-ui">
                    <template v-for="[label, value] in details" :key="label"><dt class="text-ink-2">{{ label }}</dt><dd>{{ value ?? '—' }}</dd></template>
                </dl>
                <div v-if="disposal" class="mt-4 max-w-[760px] rounded-panel border border-line p-3 text-ui">
                    <p class="font-medium">{{ disposal.kind === 'sale' ? 'Sold' : 'Written off' }} on {{ formatDate(disposal.date) }} ({{ disposal.number }})</p>
                    <p class="text-ink-2">Proceeds {{ formatMoney(disposal.proceeds) }} BDT · net book value {{ formatMoney(disposal.nbv) }} BDT ·
                        <span :class="disposal.gain_loss.startsWith('-') ? 'text-danger' : 'text-ok'">{{ disposal.gain_loss.startsWith('-') ? 'loss' : 'gain' }} {{ formatMoney(disposal.gain_loss.replace('-', '')) }} BDT</span></p>
                    <p class="text-ink-2">{{ disposal.reason }}</p>
                </div>
            </template>
            <template #tab-schedule>
                <div class="max-w-[760px] border border-line">
                    <DataTable id="fixed-asset-schedule" label="Depreciation schedule" :columns="scheduleColumns" :rows="schedule" :row-key="(r) => `${r.opening ? 'b' : 'm'}-${r.period}`" currency="BDT" :url-sync="false"
                        :open-on-click="false" compact-toolbar empty-text="No depreciation posted yet; the monthly batch posts it." />
                </div>
            </template>
            <template #tab-movements>
                <div class="max-w-[900px] border border-line">
                    <DataTable id="fixed-asset-movements" label="Movements" :columns="movementColumns" :rows="movements" :row-key="(m) => `${m.moved_on}-${m.from}-${m.to}`" :url-sync="false"
                        :open-on-click="false" compact-toolbar empty-text="The asset has not moved since it was acquired." />
                </div>
            </template>
        </ObjectPage>
        <Drawer v-model:open="moving" title="Move to another branch">
            <FormLayout submit-label="Review the journal" :dirty="move.form.isDirty" :processing="move.form.processing" :error="(move.form.errors as Record<string, string>).form" @submit="move.review" @cancel="moving = false">
                <p class="text-ui text-ink-2">The asset's cost and accumulated depreciation move to the new branch; nothing reaches profit and loss.</p>
                <Field id="to_branch_id" label="To branch" :error="move.form.errors.to_branch_id"><SelectInput id="to_branch_id" v-model="move.form.to_branch_id" :options="branches.filter((b) => b.id !== asset.branch_id).map((b) => ({ value: b.id, label: b.label }))" /></Field>
                <Field id="moved_on" label="Moved on" :error="move.form.errors.moved_on"><DateInput v-model="move.form.moved_on" /></Field>
                <Field id="location" label="New location" optional :error="move.form.errors.location"><TextInput v-model="move.form.location" /></Field>
                <Field id="reason" label="Reason" :error="move.form.errors.reason"><TextInput v-model="move.form.reason" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="disposing" title="Dispose of the asset">
            <FormLayout submit-label="Review the gain or loss" :dirty="dispose.form.isDirty" :processing="dispose.form.processing" :error="(dispose.form.errors as Record<string, string>).form" @submit="dispose.review" @cancel="disposing = false">
                <p class="text-ui text-ink-2">Net book value today {{ formatMoney(asset.nbv) }} BDT. The month of disposal is not depreciated.</p>
                <Field id="kind" label="How" :error="dispose.form.errors.kind"><SelectInput id="kind" v-model="dispose.form.kind" :options="[{ value: 'sale', label: 'Sold' }, { value: 'write_off', label: 'Scrapped or written off' }]" /></Field>
                <Field id="disposal_date" label="Date" :error="dispose.form.errors.disposal_date"><DateInput v-model="dispose.form.disposal_date" /></Field>
                <template v-if="dispose.form.kind === 'sale'">
                    <Field id="proceeds" label="Sale proceeds (BDT)" :error="dispose.form.errors.proceeds"><MoneyInput v-model="dispose.form.proceeds" /></Field>
                    <Field id="bank_account_id" label="Received into" :error="dispose.form.errors.bank_account_id"><SelectInput id="bank_account_id" v-model="dispose.form.bank_account_id" :options="bankAccounts.map((b) => ({ value: b.id, label: b.label }))" /></Field>
                </template>
                <Field id="reason" label="Reason" :error="dispose.form.errors.reason"><TextInput v-model="dispose.form.reason" /></Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="move.previewOpen.value" :result="move.preview.value" :title="`Move ${asset.number}?`" confirm-label="Move the asset" currency="BDT" :processing="move.form.processing" @confirm="move.post" />
        <JournalPreviewDialog v-model:open="dispose.previewOpen.value" :result="dispose.preview.value" :title="`Dispose of ${asset.number}?`" confirm-label="Dispose" currency="BDT" :processing="dispose.form.processing" @confirm="dispose.post" />
    </AppLayout>
</template>
