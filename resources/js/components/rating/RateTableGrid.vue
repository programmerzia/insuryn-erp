<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import { Button } from '@/components/ui/button';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';
import { type RateValueType, type RowDraft, type StoredRow, emptyDraft, formatHundredths, formatRate, formatWhole, rateUnit, rowDraft, rowPayload } from '@/lib/rating';

/**
 * One rate table of a plan as a grid (Phase 3 design §6 "tables grid with effective dates", slice R10a): a column per dimension (band tables: from,
 * to and label), the value in the unit people read (D-20), and each row's own dates. While the plan is a draft every cell is an input: Tab moves along
 * the row, Enter saves the table, "Add row" puts the cursor in the new row. The whole table is saved at once; cells that cannot be read are named
 * before anything is sent, and the server's refusal shows under the grid.
 */
export interface RateTable { code: string; name: string; dimensions: string[]; value_type: RateValueType; rows: StoredRow[] }
const props = defineProps<{ planId: string; table: RateTable; currency: string; editable: boolean }>();

const typeWords: Record<RateValueType, string> = { rate_pm: 'Rate per mille', rate_pct: 'Rate in percent', flat: 'Flat amount', band: 'Bands' };
const drafts = ref<RowDraft[]>(props.table.rows.map((row) => rowDraft(props.table.value_type, row)));
const cellErrors = ref<Record<number, Record<string, string>>>({});
const form = useForm<{ rows: StoredRow[] }>({ rows: [] });
const grid = ref<HTMLElement | null>(null);
const isBand = computed(() => props.table.value_type === 'band');
const unit = computed(() => rateUnit(props.table.value_type, props.currency));
const dirty = computed(() => JSON.stringify(drafts.value) !== JSON.stringify(props.table.rows.map((row) => rowDraft(props.table.value_type, row))));
const serverErrors = computed(() => Object.entries(form.errors as Record<string, string>).filter(([field]) => field !== 'reason'));

watch(() => props.table.rows, (rows) => {
    drafts.value = rows.map((row) => rowDraft(props.table.value_type, row));
    cellErrors.value = {};
});

async function addRow(): Promise<void> {
    drafts.value.push(emptyDraft(props.table.dimensions));
    await nextTick();
    const inputs = grid.value?.querySelectorAll<HTMLInputElement>(`tr[data-row="${drafts.value.length - 1}"] input`);
    inputs?.[0]?.focus();
}

function save(): void {
    const errors: Record<number, Record<string, string>> = {};
    const rows = drafts.value.map((draft, i) => {
        const result = rowPayload(props.table.value_type, props.table.dimensions, draft);
        if (Object.keys(result.errors).length) errors[i] = result.errors;
        return result.row;
    });
    cellErrors.value = errors;
    if (Object.keys(errors).length) return;
    form.rows = rows;
    form.put(`/rating/plans/${props.planId}/tables/${props.table.code}/rows`, { preserveScroll: true });
}

async function removeTable(): Promise<void> {
    if (await confirmAction({ title: `Remove table ${props.table.code}?`, body: `The table and its ${props.table.rows.length} rows leave this draft. Steps that look it up stop the plan being approved.`, confirmLabel: 'Remove table', tone: 'danger' })) {
        router.delete(`/rating/plans/${props.planId}/tables/${props.table.code}`, { preserveScroll: true });
    }
}

const cellError = (row: number, field: string) => cellErrors.value[row]?.[field] ?? (form.errors as Record<string, string>)[`rows.${row}.${field === 'value' ? (props.table.value_type === 'flat' ? 'value_minor' : 'value_bp') : field}`];
const inputClass = 'h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body aria-[invalid=true]:border-danger';
</script>

<template>
    <section class="grid gap-2" :aria-labelledby="`table-${table.code}`">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 :id="`table-${table.code}`" class="text-section font-semibold">{{ table.name }}</h2>
            <span class="text-ui text-ink-2">{{ table.code }} · {{ typeWords[table.value_type] }}<template v-if="table.dimensions.length"> by {{ table.dimensions.join(', ') }}</template></span>
            <Button v-if="editable" variant="ghost" size="sm" class="ml-auto" @click="removeTable">Remove table</Button>
        </div>
        <form ref="grid" novalidate @submit.prevent="save">
            <div class="overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2">
                        <tr class="h-(--row-h)">
                            <th v-for="d in table.dimensions" :key="d" class="border-b border-line px-2 text-left font-medium">{{ d }}</th>
                            <template v-if="isBand">
                                <th class="border-b border-line px-2 text-right font-medium">From</th>
                                <th class="border-b border-line px-2 text-right font-medium">Below</th>
                                <th class="border-b border-line px-2 text-left font-medium">Label</th>
                            </template>
                            <th class="border-b border-line px-2 text-right font-medium">{{ isBand ? 'Rate (%)' : `Value (${unit})` }}</th>
                            <th class="border-b border-line px-2 text-left font-medium">Row from</th>
                            <th class="border-b border-line px-2 text-left font-medium">Row until</th>
                            <th v-if="editable" class="border-b border-line px-2"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody v-if="editable">
                        <tr v-for="(draft, i) in drafts" :key="i" :data-row="i" class="align-top">
                            <td v-for="d in table.dimensions" :key="d" class="min-w-32 border-b border-line px-2 py-1">
                                <input v-model="draft.keys[d]" :class="inputClass" :aria-label="`Row ${i + 1} ${d}`" :aria-invalid="!!cellError(i, `keys.${d}`)" />
                            </td>
                            <template v-if="isBand">
                                <td class="w-28 border-b border-line px-2 py-1"><input v-model="draft.band_from" inputmode="numeric" :class="[inputClass, 'text-right tabular-nums']" :aria-label="`Row ${i + 1} band from`" :aria-invalid="!!cellError(i, 'band_from')" /></td>
                                <td class="w-28 border-b border-line px-2 py-1"><input v-model="draft.band_to" inputmode="numeric" placeholder="No end" :class="[inputClass, 'text-right tabular-nums']" :aria-label="`Row ${i + 1} band below`" :aria-invalid="!!cellError(i, 'band_to')" /></td>
                                <td class="w-32 border-b border-line px-2 py-1"><input v-model="draft.band_label" :class="inputClass" :aria-label="`Row ${i + 1} band label`" :aria-invalid="!!cellError(i, 'band_label')" /></td>
                            </template>
                            <td class="w-32 border-b border-line px-2 py-1">
                                <input v-model="draft.value" inputmode="decimal" :class="[inputClass, 'text-right tabular-nums']" :aria-label="`Row ${i + 1} value`" :aria-invalid="!!cellError(i, 'value')" />
                                <p v-if="isBand && draft.kept_minor !== null && draft.value === ''" class="mt-0.5 text-right text-ink-2 tabular-nums">{{ formatRate('flat', { value_bp: null, value_minor: draft.kept_minor }) }} {{ currency }}</p>
                            </td>
                            <td class="w-36 border-b border-line px-2 py-1"><DateInput v-model="draft.effective_from" placeholder="Plan start" :aria-label="`Row ${i + 1} from`" /></td>
                            <td class="w-36 border-b border-line px-2 py-1"><DateInput v-model="draft.effective_to" placeholder="Plan end" :aria-label="`Row ${i + 1} until`" :aria-invalid="!!cellError(i, 'effective_to')" /></td>
                            <td class="w-16 border-b border-line px-2 py-1 text-right"><Button variant="ghost" size="sm" :aria-label="`Remove row ${i + 1}`" @click="drafts.splice(i, 1)">Remove</Button></td>
                        </tr>
                        <tr v-if="drafts.length === 0"><td :colspan="table.dimensions.length + (isBand ? 7 : 4)" class="px-2 py-3 text-ui text-ink-2">No rows. Add the first rate.</td></tr>
                    </tbody>
                    <tbody v-else>
                        <tr v-for="(row, i) in table.rows" :key="i" class="h-(--row-h)">
                            <td v-for="d in table.dimensions" :key="d" class="border-b border-line px-2">{{ row.keys[d] }}</td>
                            <template v-if="isBand">
                                <td class="border-b border-line px-2 text-right tabular-nums">{{ formatWhole(row.band_from) }}</td>
                                <td class="border-b border-line px-2 text-right tabular-nums">{{ row.band_to === null ? 'No end' : formatWhole(row.band_to) }}</td>
                                <td class="border-b border-line px-2">{{ row.band_label }}</td>
                            </template>
                            <td class="border-b border-line px-2 text-right tabular-nums">
                                <template v-if="isBand">{{ row.value_bp !== null ? formatHundredths(row.value_bp) : row.value_minor !== null ? `${formatRate('flat', row)} ${currency}` : '' }}</template>
                                <template v-else>{{ formatRate(table.value_type, row) }}</template>
                            </td>
                            <td class="border-b border-line px-2 tabular-nums">{{ row.effective_from ? formatDate(row.effective_from) : 'Plan start' }}</td>
                            <td class="border-b border-line px-2 tabular-nums">{{ row.effective_to ? formatDate(row.effective_to) : 'Plan end' }}</td>
                        </tr>
                        <tr v-if="table.rows.length === 0"><td :colspan="table.dimensions.length + (isBand ? 6 : 3)" class="px-2 py-3 text-ui text-ink-2">No rows.</td></tr>
                    </tbody>
                </table>
            </div>
            <ul v-if="Object.keys(cellErrors).length || serverErrors.length" class="mt-1 grid gap-0.5 text-dense text-danger" role="alert">
                <template v-for="(fields, row) in cellErrors" :key="`c${row}`"><li v-for="(message, field) in fields" :key="field">Row {{ Number(row) + 1 }}, {{ String(field).replace('keys.', '') }}: {{ message }}</li></template>
                <li v-for="[field, message] in serverErrors" :key="field">{{ field.startsWith('rows.') ? `Row ${Number(field.split('.')[1]) + 1}: ` : '' }}{{ message }}</li>
            </ul>
            <div v-if="editable" class="mt-2 flex flex-wrap items-center gap-2">
                <Button variant="ghost" size="sm" @click="addRow">Add row</Button>
                <Button type="submit" variant="secondary" :disabled="form.processing || !dirty">Save table</Button>
                <Button v-if="dirty" variant="ghost" size="sm" @click="drafts = table.rows.map((row) => rowDraft(table.value_type, row)); cellErrors = {}">Discard changes</Button>
                <span class="text-dense text-ink-2">Enter saves. Empty row dates follow the plan's.</span>
            </div>
        </form>
    </section>
</template>
