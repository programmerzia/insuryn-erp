<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/**
 * Market gap G5, Regulatory → Returns: pick a quarter or year, see the set of forms with their status (not generated, draft, reviewed, filed), preview a form
 * as the regulator's layout names it, download the set or one form as XLSX (a sheet per form) or PDF, generate, mark reviewed and mark filed with the filing
 * date and the regulator's reference.
 */
interface FormRow { id: string | null; code: string; title: string; status: string; filed_on: string | null; filing_reference: string | null; generated: string | null; reviewed: string | null; filed_by: string | null }
interface Section { key: string; title: string; columns: { key: string; label: string; numeric: boolean }[]; rows: Record<string, string>[]; totals: Record<string, string> | null }
const props = defineProps<{
    period: { key: string; label: string; start: string; end: string };
    periods: { value: string; label: string }[];
    forms: FormRow[];
    preview: { title: string; period_label: string; entity_name: string; currency: string; notes: string[]; sections: Section[] } | null;
    selected: string | null;
    can: { generate: boolean; review: boolean; file: boolean; export: boolean };
    today: string;
}>();

const filing = reactive({ filed_on: props.today, reference: '' });
const current = computed(() => props.forms.find((f) => f.code === props.selected) ?? null);
const generated = computed(() => props.forms.filter((f) => f.status !== 'not_generated').length);
const filed = computed(() => props.forms.filter((f) => f.status === 'filed').length);
const exportUrl = (format: 'xlsx' | 'pdf', form?: string) => `/regulatory/returns/export?period=${encodeURIComponent(props.period.key)}&format=${format}${form ? `&form=${form}` : ''}`;

function go(query: Record<string, string>): void {
    router.get('/regulatory/returns', { period: props.period.key, form: props.selected ?? '', ...query }, { preserveScroll: true });
}
function generate(): void {
    router.post('/regulatory/returns/generate', { period: props.period.key }, { preserveScroll: true });
}
function review(form: FormRow): void {
    if (form.id) router.post(`/regulatory/returns/${form.id}/review`, {}, { preserveScroll: true });
}
function file(form: FormRow): void {
    if (form.id) router.post(`/regulatory/returns/${form.id}/file`, { ...filing }, { preserveScroll: true, onSuccess: () => (filing.reference = '') });
}
</script>

<template>
    <AppLayout help="regulatory" title="Regulatory returns" fill>
        <div class="flex min-h-11 flex-wrap items-center gap-2 border-b border-line px-3 py-1">
            <h1 class="mr-3 text-section font-semibold">Regulatory returns</h1>
            <Button v-if="can.generate" size="md" @click="generate">{{ generated ? 'Generate again' : 'Generate returns' }}</Button>
            <span v-else class="text-ui text-ink-2" data-testid="permission-hint">Only the finance manager or CFO can generate returns.</span>
            <label class="sr-only" for="return-period">Period</label>
            <SelectInput id="return-period" :model-value="period.key" class="w-60" :options="periods" @update:model-value="(v) => go({ period: String(v) })" />
            <span class="text-ui text-ink-2">{{ generated }} of {{ forms.length }} generated · {{ filed }} filed</span>
            <div class="ml-auto flex items-center gap-1">
                <Link href="/regulatory" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2">Regulatory dashboard</Link>
                <a v-if="can.export" :href="exportUrl('xlsx')" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" aria-label="Export the returns set as XLSX" download>Export XLSX</a>
                <a v-if="can.export" :href="exportUrl('pdf')" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" aria-label="Export the returns set as PDF" download>Export PDF</a>
            </div>
        </div>
        <div class="flex min-h-0 flex-1 flex-col md:flex-row">
            <nav class="shrink-0 border-b border-line md:w-80 md:overflow-auto md:border-r md:border-b-0" aria-label="Forms">
                <ul>
                    <li v-for="form in forms" :key="form.code">
                        <button type="button" class="grid w-full gap-1 border-b border-line px-4 py-3 text-left hover:bg-surface-2" :class="{ 'bg-surface-2': form.code === selected }" @click="go({ form: form.code })">
                            <span class="text-ui font-medium text-ink">{{ form.title }}</span>
                            <span class="flex items-center gap-2 text-dense text-ink-2">
                                <StatusBadge :status="form.status" />
                                <span v-if="form.status === 'filed'">{{ formatDate(form.filed_on) }} · {{ form.filing_reference }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
                <p class="px-4 py-3 text-dense text-ink-2">Form titles are descriptive; IDRA's form numbers and wording are to be confirmed (layouts are configuration).</p>
            </nav>
            <main class="min-w-0 flex-1 overflow-auto px-6 py-4 max-sm:px-4">
                <template v-if="preview && current">
                    <header class="mb-3 flex flex-wrap items-start gap-3">
                        <div class="min-w-0">
                            <h2 class="text-section font-semibold text-ink">{{ preview.title }}</h2>
                            <p class="text-ui text-ink-2">{{ preview.entity_name }} · {{ preview.period_label }} · amounts in {{ preview.currency }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-3 text-dense text-ink-2">
                                <StatusBadge :status="current.status" />
                                <span v-if="current.status === 'not_generated'">Live figures — generate to keep a copy as filed.</span>
                                <span v-if="current.generated">Generated by {{ current.generated }}</span>
                                <span v-if="current.reviewed">Reviewed by {{ current.reviewed }}</span>
                                <span v-if="current.status === 'filed'">Filed {{ formatDate(current.filed_on) }} by {{ current.filed_by }}, reference {{ current.filing_reference }}</span>
                            </p>
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <a v-if="can.export" :href="exportUrl('xlsx', current.code)" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" :aria-label="`Export ${current.title} as XLSX`" download>Export XLSX</a>
                            <a v-if="can.export" :href="exportUrl('pdf', current.code)" class="inline-flex h-8 items-center rounded-control px-2 text-ui text-accent-text hover:bg-surface-2" :aria-label="`Export ${current.title} as PDF`" download>Export PDF</a>
                            <Button v-if="can.review && current.status === 'draft'" variant="secondary" @click="review(current)">Mark reviewed</Button>
                        </div>
                    </header>

                    <section v-for="section in preview.sections" :key="section.key" class="mb-5">
                        <h3 class="mb-1 text-ui font-medium text-ink">{{ section.title }}</h3>
                        <div class="overflow-x-auto border border-line">
                            <table class="w-full text-ui">
                                <thead>
                                    <tr class="border-b border-line bg-surface-2 text-ink-2">
                                        <th v-for="column in section.columns" :key="column.key" scope="col" class="px-2 py-1.5 font-normal" :class="column.numeric ? 'text-right' : 'text-left'">{{ column.label }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, index) in section.rows" :key="index" class="border-b border-line last:border-b-0">
                                        <td v-for="column in section.columns" :key="column.key" class="px-2 py-1.5" :class="column.numeric ? 'text-right tabular-nums' : 'text-left'">{{ row[column.key] }}</td>
                                    </tr>
                                    <tr v-if="section.rows.length === 0"><td :colspan="section.columns.length" class="px-2 py-3 text-ink-2">Nothing to report for the period.</td></tr>
                                    <tr v-if="section.totals" class="border-t border-line bg-surface-2 font-medium">
                                        <td v-for="column in section.columns" :key="column.key" class="px-2 py-1.5" :class="column.numeric ? 'text-right tabular-nums' : 'text-left'">{{ section.totals[column.key] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <ul class="mb-5 grid gap-1 text-dense text-ink-2">
                        <li v-for="note in preview.notes" :key="note">{{ note }}</li>
                    </ul>

                    <form v-if="can.file && current.status === 'reviewed'" class="grid max-w-xl gap-3 border-t border-line pt-4 sm:grid-cols-[10rem_1fr_auto] sm:items-end" @submit.prevent="file(current)">
                        <Field id="filed_on" label="Filed on"><DateInput id="filed_on" v-model="filing.filed_on" :min="period.start" :max="today" /></Field>
                        <Field id="filing_reference" label="Regulator's reference"><TextInput id="filing_reference" v-model="filing.reference" :maxlength="100" placeholder="Acknowledgement number" /></Field>
                        <Button type="submit" :disabled="filing.reference.trim() === ''">Mark filed</Button>
                    </form>
                    <p v-else-if="can.file && current.status !== 'filed'" class="border-t border-line pt-4 text-ui text-ink-2">{{ current.id ? 'Mark the form reviewed to file it.' : 'Generate the returns to file this form.' }}</p>
                </template>
                <p v-else class="text-ui text-ink-2">Choose a form.</p>
            </main>
        </div>
    </AppLayout>
</template>
