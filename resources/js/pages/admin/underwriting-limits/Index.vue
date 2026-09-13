<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';

/**
 * Admin → Underwriting limits (Phase 3 design §5, slice R5): the largest sum insured each role may accept per product class. A proposal above the submitter's
 * limit is referred, and only someone whose limit covers it approves it. A limit in force is never rewritten: a change ends it the day the change starts.
 */
interface LimitRow {
    id: string; role_code: string; role_name: string | null; class_code: string; class_name: string | null; max_sum_insured: string; effective_from: string;
    effective_to: string | null; status: string; verify: boolean;
}
const props = defineProps<{ limits: LimitRow[]; roles: { value: string; label: string }[]; classes: { value: string; label: string }[]; currency: string; today: string }>();

const active = ref<string | null>(null);
const drawerOpen = ref(false);
const ending = ref<LimitRow | null>(null);
const form = useForm({ role_code: '', class_code: '', max_sum_insured: '', effective_from: props.today });
const endForm = useForm({ effective_to: props.today });
const errors = computed(() => form.errors as Record<string, string>);

function openSet(row: LimitRow | null): void {
    form.defaults({ role_code: row?.role_code ?? props.roles[0]?.value ?? '', class_code: row?.class_code ?? props.classes[0]?.value ?? '', max_sum_insured: row ? formatMoney(row.max_sum_insured) : '', effective_from: props.today });
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function submit(): void {
    form.post('/admin/underwriting-limits', { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) });
}
function submitEnd(): void {
    if (ending.value) endForm.post(`/admin/underwriting-limits/${ending.value.id}/end`, { preserveScroll: true, onSuccess: () => (ending.value = null) });
}
const columns: DataColumn<LimitRow>[] = [
    { id: 'class', header: 'Class', value: (l) => l.class_name ?? l.class_code, width: 160, filterOptions: props.classes.map((c) => c.label) },
    { id: 'role', header: 'Role', value: (l) => l.role_name ?? `${l.role_code} (role removed)`, width: 200 },
    { id: 'limit', header: `Largest sum insured (${props.currency})`, type: 'money', value: (l) => l.max_sum_insured },
    { id: 'from', header: 'From', type: 'date', value: (l) => l.effective_from, width: 120 },
    { id: 'to', header: 'Until', type: 'date', value: (l) => l.effective_to, width: 120 },
    { id: 'status', header: 'Status', type: 'status', value: (l) => l.status, filterOptions: ['in_force', 'scheduled', 'ended'] },
];
</script>

<template>
    <AppLayout title="Underwriting limits" fill>
        <QueueView
            id="admin-underwriting-limits"
            v-model:active="active"
            title="Underwriting limits"
            :columns="columns"
            :rows="limits"
            :row-key="(l) => l.id"
            :currency="currency"
            empty-text="No underwriting limits yet, so every proposal is referred."
            :action="{ label: 'Set a limit' }"
            :inspector-title="(l) => `${l.role_name ?? l.role_code} · ${l.class_name ?? l.class_code}`"
            :inspector-subtitle="(l) => `${formatMoney(l.max_sum_insured)} ${currency}`"
            @action="openSet(null)"
        >
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Largest sum insured', value: `${formatMoney(row.max_sum_insured)} ${currency}`, num: true },
                        { label: 'From', value: formatDate(row.effective_from) },
                        { label: 'Until', value: row.effective_to ? `${formatDate(row.effective_to)} (not included)` : 'No end date' },
                        { label: 'Source', value: row.verify ? 'Placeholder default, to verify' : 'Set by an administrator' },
                    ]"
                />
                <div v-if="row.status !== 'ended'" class="mt-4 flex gap-2">
                    <Button variant="secondary" @click="openSet(row)">Change</Button>
                    <Button variant="ghost" @click="ending = row; endForm.effective_to = today">End</Button>
                </div>
            </template>
        </QueueView>

        <Drawer v-model:open="drawerOpen" title="Set an underwriting limit">
            <p class="mb-4 text-ui text-ink-2">A limit already in force for the role and class ends the day this one starts.</p>
            <FormLayout submit-label="Set limit" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="drawerOpen = false">
                <Field id="role_code" label="Role" :error="errors.role_code"><SelectInput id="role_code" v-model="form.role_code" :options="roles" /></Field>
                <Field id="class_code" label="Product class" :error="errors.class_code"><SelectInput id="class_code" v-model="form.class_code" :options="classes" /></Field>
                <Field id="max_sum_insured" :label="`Largest sum insured (${currency})`" :error="errors.max_sum_insured"><MoneyInput id="max_sum_insured" v-model="form.max_sum_insured" /></Field>
                <Field id="effective_from" label="From" :error="errors.effective_from"><DateInput id="effective_from" v-model="form.effective_from" /></Field>
            </FormLayout>
        </Drawer>

        <Drawer :open="ending !== null" title="End underwriting limit" @update:open="(o) => !o && (ending = null)">
            <FormLayout submit-label="End limit" :dirty="endForm.isDirty" :processing="endForm.processing" :error="(endForm.errors as Record<string, string>).form" @submit="submitEnd" @cancel="ending = null">
                <Field id="end_effective_to" label="No longer applies from" :error="endForm.errors.effective_to"><DateInput id="end_effective_to" v-model="endForm.effective_to" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
