<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { employmentTypeLabel, employmentTypes } from '@/lib/people';

/**
 * Addendum §B.10.2 payroll rules as data: provident fund, festival bonuses and tax exemption; salary structure per grade; the income tax slab table per tax year.
 * Every value is a placeholder flagged verify until the customer confirms it (CQ-J1, CQ-J3, CQ-J4). Changes apply to runs calculated afterwards.
 */
interface Structure { house_rent: string; medical: string; medical_cap: string; conveyance: string; basic_min: string; basic_max: string; verify: boolean }
interface Settings {
    pf_employee: string; pf_employer: string; pf_employment_types: string[]; festival_bonus: string; festival_bonus_min_service_months: number; festivals: { name: string; month: string }[];
    tax_exempt_fraction: string; tax_exempt_cap: string; minimum_tax: string; commission_taxable: boolean; verify: boolean; effective_from: string; tax_year_start_month: number;
}
const props = defineProps<{
    settings: Settings | null;
    grades: { id: string; code: string; name: string; structure: Structure | null; employees: number }[];
    taxYear: string;
    taxYears: string[];
    slabs: { band: string; rate: string; verify: boolean }[];
    today: string;
    can: { manage: boolean };
}>();

const drawer = ref<'settings' | 'grade' | 'slabs' | null>(null);
const gradeId = ref('');
const settingsForm = useForm({
    effective_from: props.settings?.effective_from ?? props.today, pf_employee: props.settings?.pf_employee ?? '10.00', pf_employer: props.settings?.pf_employer ?? '10.00',
    pf_employment_types: props.settings?.pf_employment_types ?? ['permanent'], festival_bonus: props.settings?.festival_bonus ?? '100.00',
    festival_bonus_min_service_months: props.settings?.festival_bonus_min_service_months ?? 6, festivals: (props.settings?.festivals ?? []).map((f) => ({ ...f })),
    tax_exempt_fraction: props.settings?.tax_exempt_fraction ?? '33.33', tax_exempt_cap: props.settings?.tax_exempt_cap ?? '500,000.00', minimum_tax: props.settings?.minimum_tax ?? '5,000.00',
    commission_taxable: props.settings?.commission_taxable ?? false,
});
const gradeForm = useForm({ effective_from: props.today, house_rent: '', medical: '', medical_cap: '', conveyance: '', basic_min: '', basic_max: '' });
const slabForm = useForm({ tax_year: props.taxYear, bands: props.slabs.map((s) => ({ band: s.band, rate: s.rate })) });

function editGrade(id: string): void {
    const grade = props.grades.find((g) => g.id === id);
    gradeId.value = id;
    Object.assign(gradeForm, { effective_from: props.today, house_rent: grade?.structure?.house_rent ?? '50.00', medical: grade?.structure?.medical ?? '10.00', medical_cap: grade?.structure?.medical_cap ?? '',
        conveyance: grade?.structure?.conveyance ?? '0.00', basic_min: grade?.structure?.basic_min ?? '', basic_max: grade?.structure?.basic_max ?? '' });
    drawer.value = 'grade';
}
function toggleType(type: string): void {
    const list = settingsForm.pf_employment_types;
    settingsForm.pf_employment_types = list.includes(type) ? list.filter((t) => t !== type) : [...list, type];
}
const done = { preserveScroll: true, onSuccess: () => (drawer.value = null) };
type GradeRow = (typeof props.grades)[number];
type SlabRow = (typeof props.slabs)[number] & { no: number };
const percentOfBasic = (v: string | undefined) => (v === undefined ? 'No structure: payroll refuses this grade' : `${v}% of basic`);
const gradeColumns: DataColumn<GradeRow>[] = [
    { id: 'grade', header: 'Grade', value: (g) => `${g.code} ${g.name}`, width: 200 },
    { id: 'house_rent', header: 'House rent', value: (g) => percentOfBasic(g.structure?.house_rent), width: 200 },
    { id: 'medical', header: 'Medical', value: (g) => (g.structure ? percentOfBasic(g.structure.medical) : null), width: 140 },
    { id: 'medical_cap', header: 'Medical cap', type: 'money', value: (g) => g.structure?.medical_cap || null },
    { id: 'conveyance', header: 'Conveyance', type: 'money', value: (g) => g.structure?.conveyance ?? null },
    { id: 'employees', header: 'Employees', type: 'number', value: (g) => g.employees, width: 100 },
    { id: 'actions', header: '', value: (g) => (g.structure?.verify ? 'verify' : ''), width: 140, hideable: false },
];
const slabColumns: DataColumn<SlabRow>[] = [
    { id: 'band', header: 'Band', value: (s) => (s.no === 0 ? 'First' : s.band ? 'Next' : 'The rest'), width: 120 },
    { id: 'width', header: 'Taxable income in the band', type: 'money', value: (s) => s.band || null },
    { id: 'rate', header: 'Rate', value: (s) => `${s.rate}%`, width: 100 },
];
</script>

<template>
    <AppLayout title="Payroll settings">
        <div class="px-6 py-4">
            <h1 class="text-title font-semibold">Payroll settings</h1>
            <p class="mt-1 max-w-[900px] text-ui text-ink-2">The payroll is calculated only from these values. They are placeholders marked <strong>verify</strong> until HR and finance confirm them against the Finance Act and the company's service rules.</p>
            <FormBanner />

            <section class="mt-6 max-w-[1100px]">
                <div class="mb-2 flex items-center justify-between"><h2 class="text-section font-semibold">Provident fund, bonuses and tax</h2><Button v-if="can.manage" variant="secondary" size="sm" @click="drawer = 'settings'">Edit</Button></div>
                <dl v-if="settings" class="grid grid-cols-2 gap-x-8 gap-y-2 border border-line p-4 text-ui md:grid-cols-4">
                    <div><dt class="text-ink-2">Provident fund</dt><dd>{{ settings.pf_employee }}% employee + {{ settings.pf_employer }}% employer, of basic</dd></div>
                    <div><dt class="text-ink-2">PF members</dt><dd>{{ settings.pf_employment_types.map(employmentTypeLabel).join(', ') }}</dd></div>
                    <div><dt class="text-ink-2">Festival bonus</dt><dd>{{ settings.festival_bonus }}% of basic, after {{ settings.festival_bonus_min_service_months }} months</dd></div>
                    <div><dt class="text-ink-2">Festivals</dt><dd>{{ settings.festivals.map((f) => `${f.name} ${f.month}`).join(' · ') }}</dd></div>
                    <div><dt class="text-ink-2">Tax exempt</dt><dd>{{ settings.tax_exempt_fraction }}% of income, at most {{ formatMoney(settings.tax_exempt_cap) }}</dd></div>
                    <div><dt class="text-ink-2">Minimum tax</dt><dd>{{ formatMoney(settings.minimum_tax) }} a year</dd></div>
                    <div><dt class="text-ink-2">Commission through payroll</dt><dd>{{ settings.commission_taxable ? 'Taxed in payroll' : 'Not taxed again (withheld when earned)' }}</dd></div>
                    <div><dt class="text-ink-2">In force from</dt><dd>{{ formatDate(settings.effective_from) }} <span v-if="settings.verify" class="ml-1 text-warn">verify</span></dd></div>
                </dl>
                <p v-else class="border border-line p-4 text-ui text-ink-2">No payroll settings yet.</p>
            </section>

            <section class="mt-8 max-w-[1100px]">
                <h2 class="mb-2 text-section font-semibold">Salary structure per grade</h2>
                <DataTable id="people-salary-structures" label="Salary structure per grade" :columns="gradeColumns" :rows="grades" :row-key="(g) => g.id" currency="BDT" :url-sync="false" empty-text="No grades yet." compact-toolbar>
                    <template #cell-actions="{ row }">
                        <span class="flex items-center justify-end gap-3"><span v-if="row.structure?.verify" class="text-warn">verify</span><Button v-if="can.manage" variant="secondary" size="sm" @click.stop="editGrade(row.id)">Edit</Button></span>
                    </template>
                </DataTable>
            </section>

            <section class="mt-8 max-w-[700px]">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 class="text-section font-semibold">Income tax slabs</h2>
                    <div class="flex items-center gap-2">
                        <label class="sr-only" for="tax_year">Tax year</label>
                        <SelectInput id="tax_year" :model-value="taxYear" class="w-32" :options="taxYears.map((y) => ({ value: y, label: `FY ${y}` }))" @update:model-value="(v) => router.get('/people/payroll-settings', { tax_year: v })" />
                        <Button v-if="can.manage" variant="secondary" size="sm" @click="drawer = 'slabs'">Edit</Button>
                    </div>
                </div>
                <DataTable id="people-tax-slabs" label="Income tax slabs" :columns="slabColumns" :rows="slabs.map((s, no) => ({ ...s, no }))" :row-key="(s) => String(s.no)" currency="BDT" :url-sync="false" :empty-text="`No slabs for FY ${taxYear}: payroll for that year is refused until they are entered.`" compact-toolbar />
                <p v-if="slabs.some((s) => s.verify)" class="mt-2 text-dense text-warn">Placeholder slabs: verify against the Finance Act for FY {{ taxYear }}.</p>
            </section>
        </div>

        <Drawer :open="drawer === 'settings'" title="Payroll settings" width="w-[520px]" @update:open="(v) => (drawer = v ? 'settings' : null)">
            <FormLayout submit-label="Save" :dirty="settingsForm.isDirty" :processing="settingsForm.processing" @submit="settingsForm.put('/people/payroll-settings', done)" @cancel="drawer = null">
                <Field id="effective_from" label="In force from" :error="settingsForm.errors.effective_from"><DateInput v-model="settingsForm.effective_from" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="pf_employee" label="Employee PF (% of basic)" :error="settingsForm.errors.pf_employee"><TextInput v-model="settingsForm.pf_employee" inputmode="decimal" /></Field>
                    <Field id="pf_employer" label="Employer PF (% of basic)" :error="settingsForm.errors.pf_employer"><TextInput v-model="settingsForm.pf_employer" inputmode="decimal" /></Field>
                </div>
                <fieldset class="grid gap-1"><legend class="text-ui font-medium">PF members</legend>
                    <label v-for="t in employmentTypes" :key="t" class="flex items-center gap-2 text-ui"><input type="checkbox" :checked="settingsForm.pf_employment_types.includes(t)" @change="toggleType(t)" /> {{ employmentTypeLabel(t) }}</label>
                </fieldset>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="festival_bonus" label="Festival bonus (% of basic)" :error="settingsForm.errors.festival_bonus"><TextInput v-model="settingsForm.festival_bonus" inputmode="decimal" /></Field>
                    <Field id="festival_bonus_min_service_months" label="After months of service" :error="settingsForm.errors.festival_bonus_min_service_months"><TextInput v-model="settingsForm.festival_bonus_min_service_months" inputmode="numeric" /></Field>
                </div>
                <fieldset class="grid gap-2"><legend class="text-ui font-medium">Festivals (paid in the month)</legend>
                    <div v-for="(f, i) in settingsForm.festivals" :key="i" class="flex items-center gap-2">
                        <TextInput v-model="f.name" placeholder="Eid-ul-Fitr" /><TextInput v-model="f.month" placeholder="2027-03" /><Button variant="secondary" size="sm" @click="settingsForm.festivals.splice(i, 1)">Remove</Button>
                    </div>
                    <Button variant="secondary" size="sm" @click="settingsForm.festivals.push({ name: '', month: '' })">Add festival</Button>
                </fieldset>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="tax_exempt_fraction" label="Tax-exempt share of income (%)" :error="settingsForm.errors.tax_exempt_fraction"><TextInput v-model="settingsForm.tax_exempt_fraction" inputmode="decimal" /></Field>
                    <Field id="tax_exempt_cap" label="Exempt at most" :error="settingsForm.errors.tax_exempt_cap"><MoneyInput id="tax_exempt_cap" v-model="settingsForm.tax_exempt_cap" /></Field>
                </div>
                <Field id="minimum_tax" label="Minimum tax a year" :error="settingsForm.errors.minimum_tax"><MoneyInput id="minimum_tax" v-model="settingsForm.minimum_tax" /></Field>
                <label class="flex items-center gap-2 text-ui"><input v-model="settingsForm.commission_taxable" type="checkbox" /> Tax commission paid through payroll again (CQ-F4 open)</label>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'grade'" title="Salary structure" width="w-[440px]" @update:open="(v) => (drawer = v ? 'grade' : null)">
            <FormLayout submit-label="Save" :dirty="gradeForm.isDirty" :processing="gradeForm.processing" @submit="gradeForm.put(`/people/payroll-settings/grades/${gradeId}`, done)" @cancel="drawer = null">
                <Field id="g_effective_from" label="In force from" :error="gradeForm.errors.effective_from"><DateInput v-model="gradeForm.effective_from" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="house_rent" label="House rent (% of basic)" :error="gradeForm.errors.house_rent"><TextInput v-model="gradeForm.house_rent" inputmode="decimal" /></Field>
                    <Field id="medical" label="Medical (% of basic)" :error="gradeForm.errors.medical"><TextInput v-model="gradeForm.medical" inputmode="decimal" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="medical_cap" label="Medical at most" optional :error="gradeForm.errors.medical_cap"><MoneyInput id="medical_cap" v-model="gradeForm.medical_cap" /></Field>
                    <Field id="conveyance" label="Conveyance (monthly)" :error="gradeForm.errors.conveyance"><MoneyInput id="conveyance" v-model="gradeForm.conveyance" /></Field>
                </div>
            </FormLayout>
        </Drawer>

        <Drawer :open="drawer === 'slabs'" title="Income tax slabs" width="w-[480px]" @update:open="(v) => (drawer = v ? 'slabs' : null)">
            <FormLayout submit-label="Save slabs" :dirty="slabForm.isDirty" :processing="slabForm.processing" @submit="slabForm.put('/people/payroll-settings/tax-slabs', done)" @cancel="drawer = null">
                <Field id="slab_year" label="Tax year" :error="slabForm.errors.tax_year"><TextInput v-model="slabForm.tax_year" placeholder="2026-27" /></Field>
                <p class="text-dense text-ink-2">Bands in order: the width of taxable income in each band; leave the last band empty for "the rest".</p>
                <div v-for="(b, i) in slabForm.bands" :key="i" class="flex items-center gap-2">
                    <MoneyInput v-model="b.band" placeholder="The rest" /><TextInput v-model="b.rate" inputmode="decimal" placeholder="Rate %" /><Button variant="secondary" size="sm" @click="slabForm.bands.splice(i, 1)">Remove</Button>
                </div>
                <Button variant="secondary" size="sm" @click="slabForm.bands.push({ band: '', rate: '' })">Add band</Button>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
