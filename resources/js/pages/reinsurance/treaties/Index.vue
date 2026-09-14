<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/**
 * Reinsurance → Treaties (market gap G4): the treaties per class and underwriting year — quota share or surplus, commission and the SBC compulsory share — and
 * the reinsurers they cede to (the Reinsurers panel). Sadharan Bima Corporation (SBC), the state reinsurer, takes its compulsory share of every class first.
 */
interface TreatyRow { id: string; code: string; name: string; class: string; year: number; period_from: string; period_to: string; type: string; status: string; terms: string; commission: string; sbc_share: string; participants: string }
interface ReinsurerRow { id: string; code: string; name: string; rating: string | null; rating_agency: string | null; country: string; is_state_reinsurer: boolean; status: string }
const props = defineProps<{ treaties: TreatyRow[]; reinsurers: ReinsurerRow[]; canManage: boolean; currency: string }>();

const TYPES: Record<string, string> = { quota_share: 'Quota share', surplus: 'Surplus' };
const active = ref<string | null>(null);
const panel = ref<'reinsurers' | 'add' | null>(null);
const form = useForm({ name: '', code: '', rating: '', rating_agency: '', country: 'BD', is_state_reinsurer: false });
const errors = computed(() => form.errors as Record<string, string>);
const hasSbc = computed(() => props.reinsurers.some((r) => r.is_state_reinsurer));
function submit(): void {
    form.post('/reinsurance/reinsurers', { preserveScroll: true, onSuccess: () => { form.reset(); panel.value = 'reinsurers'; } });
}

const columns: DataColumn<TreatyRow>[] = [
    { id: 'code', header: 'Treaty', value: (t) => t.code, href: (t) => `/reinsurance/treaties/${t.id}`, width: 150 },
    { id: 'name', header: 'Name', value: (t) => t.name, width: 220, muted: true },
    { id: 'class', header: 'Class', value: (t) => t.class, width: 150, filterOptions: [...new Set(props.treaties.map((t) => t.class))] },
    { id: 'year', header: 'Year', type: 'number', value: (t) => t.year, width: 80 },
    { id: 'type', header: 'Type', value: (t) => TYPES[t.type] ?? t.type, width: 110, filterOptions: Object.values(TYPES) },
    { id: 'terms', header: `Terms (${props.currency})`, value: (t) => t.terms, width: 240 },
    { id: 'commission', header: 'Commission %', type: 'number', value: (t) => t.commission, width: 110 },
    { id: 'sbc', header: 'SBC share %', type: 'number', value: (t) => t.sbc_share, width: 100 },
    { id: 'participants', header: 'Reinsurers', value: (t) => t.participants, width: 200 },
    { id: 'status', header: 'Status', type: 'status', value: (t) => t.status, filterOptions: ['active', 'inactive'] },
];
const reinsurerColumns: DataColumn<ReinsurerRow>[] = [
    { id: 'code', header: 'Code', value: (r) => r.code, width: 90 },
    { id: 'name', header: 'Reinsurer', value: (r) => (r.is_state_reinsurer ? `${r.name} (state reinsurer)` : r.name), width: 240 },
    { id: 'rating', header: 'Rating', value: (r) => (r.rating ? `${r.rating}${r.rating_agency ? ` (${r.rating_agency})` : ''}` : '—'), width: 120 },
    { id: 'country', header: 'Country', value: (r) => r.country, width: 80 },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status },
];
const newTreaty = computed(() => (props.canManage ? { label: 'New treaty', href: '/reinsurance/treaties/create' } : null));
</script>

<template>
    <AppLayout help="reinsurance" title="Treaties" fill>
        <QueueView
            id="ri-treaties"
            v-model:active="active"
            title="Treaties"
            :columns="columns"
            :rows="treaties"
            :row-key="(t) => t.id"
            :currency="currency"
            empty-text="No treaties yet: every risk is retained."
            :action="newTreaty"
            :inspector-title="(t) => t.code"
            :inspector-subtitle="(t) => t.name"
        >
            <template #toolbar>
                <button type="button" class="text-ui text-accent-text hover:underline" @click="panel = 'reinsurers'">Reinsurers ({{ reinsurers.length }})</button>
            </template>
            <template #details="{ row }">
                <DetailList :items="[
                    { label: 'Status' }, { label: 'Class', value: row.class }, { label: 'Underwriting year', value: String(row.year) },
                    { label: 'Covers policies starting', value: `${formatDate(row.period_from)} to ${formatDate(row.period_to)}` },
                    { label: 'Type', value: TYPES[row.type] ?? row.type }, { label: 'Terms', value: row.terms },
                    { label: 'Commission', value: `${row.commission}%` }, { label: 'SBC compulsory share', value: `${row.sbc_share}%` }, { label: 'Reinsurers', value: row.participants || '—' },
                ]">
                    <template #Status><StatusBadge :status="row.status" /></template>
                </DetailList>
                <Link :href="`/reinsurance/treaties/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the treaty</Link>
            </template>
        </QueueView>

        <Drawer :open="panel === 'reinsurers'" title="Reinsurers" width="w-[720px]" @update:open="(o) => (panel = o ? 'reinsurers' : null)">
            <p class="mb-3 text-ui text-ink-2">Each policy is ceded when it is issued, endorsed or cancelled: first the SBC compulsory share, then the treaty for its class whose period covers the inception date.</p>
            <div v-if="canManage" class="mb-3">
                <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="panel = 'add'">Add reinsurer</button>
            </div>
            <DataTable id="ri-reinsurers" label="Reinsurers" :columns="reinsurerColumns" :rows="reinsurers" :row-key="(r) => r.id" :url-sync="false" :open-on-click="false" compact-toolbar
                empty-text="No reinsurers yet. Add Sadharan Bima Corporation first." />
        </Drawer>

        <Drawer :open="panel === 'add'" title="Add a reinsurer" @update:open="(o) => (panel = o ? 'add' : null)">
            <FormLayout submit-label="Add reinsurer" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="panel = 'reinsurers'">
                <Field id="ri_name" label="Name" :error="errors.name"><TextInput id="ri_name" v-model="form.name" /></Field>
                <Field id="ri_code" label="Code" hint="Short code on bordereaux and statements, like SBC." :error="errors.code"><TextInput id="ri_code" v-model="form.code" :maxlength="32" /></Field>
                <Field id="ri_rating" label="Security rating" optional :error="errors.rating"><TextInput id="ri_rating" v-model="form.rating" placeholder="A+" /></Field>
                <Field id="ri_agency" label="Rating agency" optional :error="errors.rating_agency"><TextInput id="ri_agency" v-model="form.rating_agency" placeholder="AM Best" /></Field>
                <Field id="ri_country" label="Country (2-letter code)" :error="errors.country"><TextInput id="ri_country" v-model="form.country" :maxlength="2" /></Field>
                <label v-if="!hasSbc" class="flex items-center gap-2 text-ui"><input v-model="form.is_state_reinsurer" type="checkbox" /> State reinsurer (Sadharan Bima Corporation): takes the compulsory share</label>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
