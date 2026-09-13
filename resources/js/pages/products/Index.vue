<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

interface Version { id: string; version: number; effective_from: string; effective_to: string | null; term_months: number; earning_method: string; tax_profile: { tax_type: string | null; jurisdiction: string | null; inclusive: boolean; refund_tax_on_cancellation: boolean } }
const props = defineProps<{
    products: { id: string; code: string; name: string; lob: string; versions: Version[] }[];
    earningMethods: string[];
    commissionPlans: { id: string; code: string; name: string }[];
}>();

const productForm = useForm({ code: '', name: '', lob: '' });
const versionFor = ref<string | null>(null);
const versionForm = useForm({ effective_from: '', effective_to: '', term_months: 12, earning_method: props.earningMethods[0] ?? '', tax_type: 'VAT', jurisdiction: 'BD', inclusive: true, refund_tax_on_cancellation: true, commission_plan_id: '' });
</script>

<template>
    <AppLayout title="Products">
        <PageHeader eyebrow="Operations" title="Products" description="Insurance products and their effective-dated versions: term, earning method, tax and commission." />
        <FormBanner />
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="grid gap-4 lg:col-span-2">
                <Card v-for="product in products" :key="product.id">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-section font-semibold"><span class=" text-accent-text">{{ product.code }}</span> {{ product.name }}</h2>
                        <span class="text-dense text-ink-2">{{ product.lob }}</span>
                    </div>
                    <table class="mt-3 w-full text-ui">
                        <thead class="text-left text-dense text-ink-2"><tr><th class="py-1">v</th><th>Effective</th><th>Term</th><th>Earning</th><th>Tax</th></tr></thead>
                        <tbody>
                            <tr v-for="version in product.versions" :key="version.id" class="border-t border-line">
                                <td class="py-1.5">{{ version.version }}</td>
                                <td>{{ version.effective_from }} – {{ version.effective_to ?? 'open' }}</td>
                                <td>{{ version.term_months }} months</td>
                                <td>{{ version.earning_method }}</td>
                                <td>{{ version.tax_profile.tax_type ?? 'none' }} {{ version.tax_profile.inclusive ? 'incl.' : 'excl.' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <Button variant="ghost" class="mt-3" @click="versionFor = versionFor === product.id ? null : product.id">Add version</Button>
                    <form v-if="versionFor === product.id" class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="versionForm.post(`/products/${product.id}/versions`, { onSuccess: () => (versionFor = null) })">
                        <Field id="effective_from" label="Effective from" :error="versionForm.errors.effective_from"><Input id="effective_from" v-model="versionForm.effective_from" type="date" /></Field>
                        <Field id="effective_to" label="Effective to (optional)" :error="versionForm.errors.effective_to"><Input id="effective_to" v-model="versionForm.effective_to" type="date" /></Field>
                        <Field id="term_months" label="Term (months)" :error="versionForm.errors.term_months"><Input id="term_months" v-model.number="versionForm.term_months" type="number" /></Field>
                        <Field id="earning_method" label="Earning method" :error="versionForm.errors.earning_method">
                            <SelectInput id="earning_method" v-model="versionForm.earning_method" :options="earningMethods.map((m) => ({ value: m, label: m }))" />
                        </Field>
                        <Field id="tax_type" label="Tax type" :error="versionForm.errors.tax_type"><Input id="tax_type" v-model="versionForm.tax_type" /></Field>
                        <Field id="jurisdiction" label="Jurisdiction" :error="versionForm.errors.jurisdiction"><Input id="jurisdiction" v-model="versionForm.jurisdiction" /></Field>
                        <Field id="commission_plan_id" label="Commission plan" :error="versionForm.errors.commission_plan_id">
                            <SelectInput id="commission_plan_id" v-model="versionForm.commission_plan_id" placeholder="None" :options="commissionPlans.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" />
                        </Field>
                        <div class="grid content-end gap-2 text-ui text-ink-2">
                            <label class="flex items-center gap-2"><input v-model="versionForm.inclusive" type="checkbox" class="size-4 accent-brick" /> Premium includes tax</label>
                            <label class="flex items-center gap-2"><input v-model="versionForm.refund_tax_on_cancellation" type="checkbox" class="size-4 accent-brick" /> Refund tax on cancellation</label>
                        </div>
                        <Button type="submit" :disabled="versionForm.processing" class="justify-self-start">Save version</Button>
                    </form>
                </Card>
            </div>
            <Card>
                <h2 class="text-section font-semibold">New product</h2>
                <form class="mt-4 grid gap-4" @submit.prevent="productForm.post('/products', { onSuccess: () => productForm.reset() })">
                    <Field id="code" label="Code" :error="productForm.errors.code"><Input id="code" v-model="productForm.code" /></Field>
                    <Field id="name" label="Name" :error="productForm.errors.name"><Input id="name" v-model="productForm.name" /></Field>
                    <Field id="lob" label="Line of business" :error="productForm.errors.lob"><Input id="lob" v-model="productForm.lob" placeholder="motor, fire, marine…" /></Field>
                    <Button type="submit" :disabled="productForm.processing">Create product</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
