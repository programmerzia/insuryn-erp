<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/**
 * Phase 3 design §6 tariff editor, the queue (slice R10a): every rating plan version with its class, dates, status, source and verify flag. A plan
 * manager starts a draft or copies any version into the next draft version; the plan page does the rest.
 */
interface PlanRow { id: string; code: string; name: string; class_code: string; version: number; effective_from: string; effective_to: string | null; status: string; source: string; verify: boolean }
const props = defineProps<{ plans: PlanRow[]; classes: { code: string; name: string }[]; today: string; can: { manage: boolean } }>();

const active = ref<string | null>(null);
const drawer = ref<'plan' | 'version' | null>(null);
const selected = computed(() => props.plans.find((p) => p.id === active.value) ?? null);
const className = (code: string) => props.classes.find((c) => c.code === code)?.name ?? code;
const sourceWords: Record<string, string> = { idra_tariff: 'IDRA tariff', company: 'Company' };
const planForm = useForm({ code: '', name: '', class_code: props.classes[0]?.code ?? '', effective_from: '', effective_to: '', source: 'company', verify: true, notes: '' });
const versionForm = useForm({ effective_from: '', effective_to: '' });

const columns: DataColumn<PlanRow>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, href: (p) => `/rating/plans/${p.id}`, pinTitle: (p) => `${p.code} v${p.version}`, width: 170 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 220 },
    { id: 'class', header: 'Class', value: (p) => className(p.class_code), width: 120, filterOptions: props.classes.map((c) => c.name) },
    { id: 'version', header: 'Version', type: 'number', value: (p) => p.version, width: 80 },
    { id: 'from', header: 'From', type: 'date', value: (p) => p.effective_from },
    { id: 'to', header: 'Until', type: 'date', value: (p) => p.effective_to },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status, width: 110, filterOptions: ['draft', 'approved', 'active', 'retired'] },
    { id: 'source', header: 'Source', value: (p) => sourceWords[p.source] ?? p.source, width: 110, filterOptions: Object.values(sourceWords) },
    { id: 'verify', header: 'Values', value: (p) => (p.verify ? 'Verify before use' : 'Confirmed'), width: 140, filterOptions: ['Verify before use', 'Confirmed'], muted: true },
];

function openVersion(): void {
    versionForm.reset();
    drawer.value = 'version';
}
</script>

<template>
    <AppLayout title="Tariffs" fill>
        <QueueView
            id="rating-plans"
            v-model:active="active"
            title="Tariffs"
            :columns="columns"
            :rows="plans"
            :row-key="(p) => p.id"
            empty-text="No rating plans yet: draft the tariff for a product class."
            :action="can.manage ? { label: 'New plan' } : null"
            :inspector-title="(p) => `${p.code} v${p.version}`"
            :inspector-subtitle="(p) => p.name"
            :primary-label="can.manage ? () => 'New version' : undefined"
            @action="drawer = 'plan'"
            @primary="openVersion"
        >
            <template #details="{ row }">
                <p v-if="row.verify" class="mb-3 border-l-2 border-warn pl-3 text-ui">Placeholder values — verify before use.</p>
                <DetailList :items="[
                    { label: 'Class', value: className(row.class_code) },
                    { label: 'Status', value: row.status.charAt(0).toUpperCase() + row.status.slice(1) },
                    { label: 'In force', value: `${formatDate(row.effective_from)} ${row.effective_to ? `to ${formatDate(row.effective_to)}` : 'onwards'}` },
                    { label: 'Source', value: sourceWords[row.source] },
                ]" />
                <Link :href="`/rating/plans/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the plan</Link>
            </template>
        </QueueView>

        <Drawer :open="drawer === 'plan'" title="New rating plan" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Create draft" :dirty="planForm.isDirty" :processing="planForm.processing" :error="(planForm.errors as Record<string, string>).form"
                @submit="planForm.post('/rating/plans', { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <Field id="plan_code" label="Code" hint="Upper-case letters, digits, - and _. Versions of a plan share its code." :error="planForm.errors.code"><TextInput v-model="planForm.code" placeholder="MOTOR-TARIFF" /></Field>
                <Field id="plan_name" label="Name" :error="planForm.errors.name"><TextInput v-model="planForm.name" /></Field>
                <Field id="plan_class" label="Product class" :error="planForm.errors.class_code"><SelectInput id="plan_class" v-model="planForm.class_code" :options="classes.map((c) => ({ value: c.code, label: c.name }))" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="plan_from" label="In force from" :error="planForm.errors.effective_from"><DateInput v-model="planForm.effective_from" /></Field>
                    <Field id="plan_to" label="Until" optional :error="planForm.errors.effective_to"><DateInput v-model="planForm.effective_to" /></Field>
                </div>
                <Field id="plan_source" label="Source" :error="planForm.errors.source"><SelectInput id="plan_source" v-model="planForm.source" :options="Object.entries(sourceWords).map(([value, label]) => ({ value, label }))" /></Field>
                <label class="flex items-center gap-2 text-ui"><input v-model="planForm.verify" type="checkbox" class="size-3.5 accent-accent" />Values are placeholders to verify</label>
                <Field id="plan_notes" label="Notes" optional :error="planForm.errors.notes"><TextInput v-model="planForm.notes" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'version' && selected !== null" :title="`New version of ${selected?.code}`" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Create draft version" :dirty="versionForm.isDirty" :processing="versionForm.processing" :error="(versionForm.errors as Record<string, string>).form"
                @submit="versionForm.post(`/rating/plans/${selected?.id}/versions`, { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <p class="text-ui text-ink-2">Copies v{{ selected?.version }} — its tables, rows and steps — into a draft you can change before approval.</p>
                <Field id="version_from" label="In force from" optional hint="Empty keeps the copied dates." :error="versionForm.errors.effective_from"><DateInput v-model="versionForm.effective_from" /></Field>
                <Field id="version_to" label="Until" optional :error="versionForm.errors.effective_to"><DateInput v-model="versionForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
