<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { useOnboarding } from '@/lib/onboarding';
import { usePermissions } from '@/lib/permissions';

interface Version { id: string; version: number; effective_from: string; effective_to: string | null; term_months: number; earning_method: string; tax_profile: { tax_type: string | null; jurisdiction: string | null; inclusive: boolean; refund_tax_on_cancellation: boolean } }
interface Product { id: string; code: string; name: string; lob: string; versions: Version[] }
const props = defineProps<{ products: Product[]; earningMethods: string[]; commissionPlans: { id: string; code: string; name: string }[] }>();

const { can } = usePermissions();
const onboarding = useOnboarding();
const active = ref<string | null>(null);
const drawer = ref<'product' | 'version' | null>(null);
const productForm = useForm({ code: '', name: '', lob: '' });
const versionForm = useForm({ effective_from: '', effective_to: '', term_months: 12, earning_method: props.earningMethods[0] ?? '', tax_type: 'VAT', jurisdiction: 'BD', inclusive: true, refund_tax_on_cancellation: true, commission_plan_id: '' });
const current = (p: Product) => p.versions[0];
const method = (m: string) => ({ daily_365: 'By day (365ths)', monthly: 'By month' })[m] ?? m;
const selected = computed(() => props.products.find((p) => p.id === active.value) ?? null);
const columns: DataColumn<Product>[] = [
    { id: 'code', header: 'Code', value: (p) => p.code, width: 110 },
    { id: 'name', header: 'Name', value: (p) => p.name, width: 240 },
    { id: 'lob', header: 'Line of business', value: (p) => p.lob, width: 140 },
    { id: 'versions', header: 'Versions', type: 'number', value: (p) => p.versions.length, width: 90 },
    { id: 'term', header: 'Term (months)', type: 'number', value: (p) => current(p)?.term_months ?? null, width: 110 },
    { id: 'earning', header: 'Earning', value: (p) => (current(p) ? method(current(p)!.earning_method) : 'No version yet'), width: 160, muted: true },
];
</script>

<template>
    <AppLayout title="Products" fill>
        <QueueView
            id="products"
            v-model:active="active"
            title="Products"
            :columns="columns"
            :rows="products"
            :row-key="(p) => p.id"
            empty-text="No products yet."
            :empty-action="onboarding.canSetup ? { label: 'Set up the first product', href: '/setup?step=product' } : null"
            :action="can('product.manage') ? { label: 'New product' } : null"
            :inspector-title="(p) => `${p.code} · ${p.name}`"
            :inspector-subtitle="(p) => p.lob"
            @action="drawer = 'product'"
        >
            <template #details="{ row }">
                <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
                    <thead class="text-ink-2"><tr class="h-8"><th class="border-b border-line text-left font-medium">Version</th><th class="border-b border-line text-left font-medium">From</th><th class="border-b border-line text-left font-medium">Earning</th><th class="border-b border-line text-left font-medium">Tax</th></tr></thead>
                    <tbody>
                        <tr v-for="v in row.versions" :key="v.id" class="h-8">
                            <td class="border-b border-line tabular-nums">{{ v.version }}</td>
                            <td class="border-b border-line">{{ formatDate(v.effective_from) }}<span v-if="v.effective_to" class="text-ink-2"> to {{ formatDate(v.effective_to) }}</span></td>
                            <td class="border-b border-line">{{ method(v.earning_method) }}</td>
                            <td class="border-b border-line">{{ v.tax_profile.tax_type ?? 'None' }}<span class="text-ink-2">{{ v.tax_profile.inclusive ? ' incl.' : ' excl.' }}</span></td>
                        </tr>
                        <tr v-if="row.versions.length === 0"><td colspan="4" class="py-3 text-ink-2">No versions. Add one before quoting this product.</td></tr>
                    </tbody>
                </table>
            </template>
            <template v-if="can('product.manage')" #actions>
                <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = 'version'">Add a version</button>
            </template>
        </QueueView>
        <Drawer :open="drawer === 'product'" title="New product" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Create product" :dirty="productForm.isDirty" :processing="productForm.processing" @submit="productForm.post('/products', { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <Field id="code" label="Code" :error="productForm.errors.code"><TextInput v-model="productForm.code" placeholder="MOTOR" /></Field>
                <Field id="name" label="Name" :error="productForm.errors.name"><TextInput v-model="productForm.name" /></Field>
                <Field id="lob" label="Line of business" :error="productForm.errors.lob"><TextInput v-model="productForm.lob" placeholder="motor, fire, marine" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'version' && selected !== null" :title="`New version of ${selected?.code}`" @update:open="(open) => !open && (drawer = null)">
            <FormLayout submit-label="Add version" :dirty="versionForm.isDirty" :processing="versionForm.processing" :error="(versionForm.errors as Record<string, string>).form" @submit="versionForm.post(`/products/${selected?.id}/versions`, { onSuccess: () => (drawer = null) })" @cancel="drawer = null">
                <Field id="effective_from" label="Effective from" :error="versionForm.errors.effective_from"><DateInput v-model="versionForm.effective_from" /></Field>
                <Field id="effective_to" label="Effective to" optional :error="versionForm.errors.effective_to"><DateInput v-model="versionForm.effective_to" /></Field>
                <Field id="term_months" label="Term (months)" :error="versionForm.errors.term_months"><TextInput v-model="versionForm.term_months" inputmode="numeric" /></Field>
                <Field id="earning_method" label="Earning method" :error="versionForm.errors.earning_method"><SelectInput id="earning_method" v-model="versionForm.earning_method" :options="earningMethods.map((m) => ({ value: m, label: method(m) }))" /></Field>
                <Field id="tax_type" label="Tax type" :error="versionForm.errors.tax_type"><TextInput v-model="versionForm.tax_type" /></Field>
                <Field id="jurisdiction" label="Jurisdiction" :error="versionForm.errors.jurisdiction"><TextInput v-model="versionForm.jurisdiction" /></Field>
                <label class="flex items-center gap-2 text-ui"><input v-model="versionForm.inclusive" type="checkbox" class="size-3.5 accent-accent" />Premium includes tax</label>
                <label class="flex items-center gap-2 text-ui"><input v-model="versionForm.refund_tax_on_cancellation" type="checkbox" class="size-3.5 accent-accent" />Refund tax on cancellation</label>
                <Field id="commission_plan_id" label="Commission plan" optional :error="versionForm.errors.commission_plan_id"><SelectInput id="commission_plan_id" v-model="versionForm.commission_plan_id" placeholder="None" :options="commissionPlans.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
