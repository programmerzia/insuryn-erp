<script setup lang="ts">
import { formatDate } from '@/lib/format';
import { type RateValueType, type StoredRow, formatWhole, rateWithUnit } from '@/lib/rating';

/**
 * The diff view of the tariff editor (Phase 3 design §6, slice R10a): what changed from one version of a plan to another, as RatingPlanDiff reports it —
 * header and date changes, per rate table the rows added, removed and changed (values in the unit people read), and step changes.
 */
interface Change { field: string; before: unknown; after: unknown }
interface TableDiff { code: string; name: string; value_type: RateValueType; dimensions: string[]; status: 'added' | 'removed' | 'changed'; changes: Change[];
    rows: { added: StoredRow[]; removed: StoredRow[]; changed: { before: StoredRow; after: StoredRow; fields: string[] }[] } }
interface StepRow { order_no: number; code: string; kind: string; expression: string; condition: string | null; applies_to: string | null; label_en: string; label_bn: string }
export interface Diff { identical: boolean; header: Change[]; tables: TableDiff[]; steps: { added: StepRow[]; removed: StepRow[]; changed: { code: string; changes: Change[] }[] } }
defineProps<{ diff: Diff; currency: string; fromLabel: string; toLabel: string }>();

const fieldWords: Record<string, string> = { name: 'Name', class_code: 'Class', effective_from: 'In force from', effective_to: 'Until', source: 'Source', currency: 'Currency', dimensions: 'Dimensions',
    value_type: 'Value type', order_no: 'Order', kind: 'Kind', expression: 'Expression', condition: 'Condition', applies_to: 'Coverage', label_en: 'Label (English)', label_bn: 'Label (Bangla)',
    value_bp: 'Rate', value_minor: 'Amount', band_to: 'Band below', band_label: 'Band label' };
const statusWords = { added: 'Added', removed: 'Removed', changed: 'Changed' };

function shown(field: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return field === 'effective_to' ? 'open' : '—';
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
    return String(value);
}

function rowName(table: TableDiff, row: StoredRow): string {
    const where = table.value_type === 'band' ? `${row.band_label ?? ''} [${formatWhole(row.band_from)}, ${row.band_to === null ? 'no end' : formatWhole(row.band_to)})` : table.dimensions.map((d) => row.keys[d]).join(' · ');
    return row.effective_from ? `${where} from ${formatDate(row.effective_from)}` : where;
}

const value = (table: TableDiff, row: StoredRow, currency: string) => rateWithUnit(table.value_type, row, currency);
const until = (row: StoredRow) => (row.effective_to ? formatDate(row.effective_to) : 'Plan end');
</script>

<template>
    <div class="grid max-w-[1100px] gap-6">
        <p class="text-ui text-ink-2">Changes from {{ fromLabel }} to {{ toLabel }}.</p>
        <p v-if="diff.identical" class="text-ui">The two versions have the same dates, tables, rows and steps.</p>

        <section v-if="diff.header.length" aria-labelledby="diff-header">
            <h2 id="diff-header" class="mb-2 text-section font-semibold">Plan</h2>
            <ul class="border border-line">
                <li v-for="c in diff.header" :key="c.field" class="flex flex-wrap gap-x-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                    <span class="w-32 text-ink-2">{{ fieldWords[c.field] ?? c.field }}</span>
                    <span class="tabular-nums">{{ shown(c.field, c.before) }} → <span class="font-medium">{{ shown(c.field, c.after) }}</span></span>
                </li>
            </ul>
        </section>

        <section v-for="table in diff.tables" :key="table.code" :aria-labelledby="`diff-${table.code}`">
            <h2 :id="`diff-${table.code}`" class="mb-2 text-section font-semibold">{{ table.name }} <span class="text-ui font-normal text-ink-2">{{ table.code }} · {{ statusWords[table.status] }}</span></h2>
            <ul v-if="table.changes.length" class="mb-2 text-ui">
                <li v-for="c in table.changes" :key="c.field">{{ fieldWords[c.field] ?? c.field }}: {{ shown(c.field, c.before) }} → {{ shown(c.field, c.after) }}</li>
            </ul>
            <div class="overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2">
                        <tr class="h-(--row-h)">
                            <th class="w-24 border-b border-line px-3 text-left font-medium">Change</th>
                            <th class="border-b border-line px-3 text-left font-medium">Row</th>
                            <th class="border-b border-line px-3 text-right font-medium">Before</th>
                            <th class="border-b border-line px-3 text-right font-medium">After</th>
                            <th class="border-b border-line px-3 text-left font-medium">Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in table.rows.added" :key="`a${i}`" class="h-(--row-h)">
                            <td class="border-b border-line px-3 text-ok">Added</td><td class="border-b border-line px-3">{{ rowName(table, row) }}</td>
                            <td class="border-b border-line px-3 text-right text-ink-2">—</td><td class="border-b border-line px-3 text-right tabular-nums">{{ value(table, row, currency) }}</td>
                            <td class="border-b border-line px-3 tabular-nums">{{ until(row) }}</td>
                        </tr>
                        <tr v-for="(row, i) in table.rows.removed" :key="`r${i}`" class="h-(--row-h)">
                            <td class="border-b border-line px-3 text-danger">Removed</td><td class="border-b border-line px-3">{{ rowName(table, row) }}</td>
                            <td class="border-b border-line px-3 text-right tabular-nums">{{ value(table, row, currency) }}</td><td class="border-b border-line px-3 text-right text-ink-2">—</td>
                            <td class="border-b border-line px-3 tabular-nums">{{ until(row) }}</td>
                        </tr>
                        <tr v-for="(pair, i) in table.rows.changed" :key="`c${i}`" class="h-(--row-h)">
                            <td class="border-b border-line px-3 text-warn">Changed</td>
                            <td class="border-b border-line px-3">{{ rowName(table, pair.after) }}<span v-if="pair.fields.some((f) => f === 'band_label' || f === 'band_to')" class="text-ink-2"> (was {{ rowName(table, pair.before) }})</span></td>
                            <td class="border-b border-line px-3 text-right tabular-nums">{{ value(table, pair.before, currency) }}</td>
                            <td class="border-b border-line px-3 text-right font-medium tabular-nums">{{ value(table, pair.after, currency) }}</td>
                            <td class="border-b border-line px-3 tabular-nums"><template v-if="pair.fields.includes('effective_to')">{{ until(pair.before) }} → </template>{{ until(pair.after) }}</td>
                        </tr>
                        <tr v-if="!table.rows.added.length && !table.rows.removed.length && !table.rows.changed.length"><td colspan="5" class="px-3 py-3 text-ui text-ink-2">Same rows.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="diff.steps.added.length || diff.steps.removed.length || diff.steps.changed.length" aria-labelledby="diff-steps">
            <h2 id="diff-steps" class="mb-2 text-section font-semibold">Steps</h2>
            <ul class="border border-line text-ui">
                <li v-for="s in diff.steps.added" :key="`a${s.code}`" class="border-b border-line px-3 py-2 last:border-b-0"><span class="text-ok">Added</span> {{ s.order_no }} · {{ s.code }} ({{ s.kind }}): <span class="break-all">{{ s.expression }}</span></li>
                <li v-for="s in diff.steps.removed" :key="`r${s.code}`" class="border-b border-line px-3 py-2 last:border-b-0"><span class="text-danger">Removed</span> {{ s.order_no }} · {{ s.code }} ({{ s.kind }}): <span class="break-all">{{ s.expression }}</span></li>
                <li v-for="s in diff.steps.changed" :key="`c${s.code}`" class="border-b border-line px-3 py-2 last:border-b-0">
                    <span class="text-warn">Changed</span> {{ s.code }}
                    <p v-for="c in s.changes" :key="c.field" class="ml-4 break-all text-ink-2">{{ fieldWords[c.field] ?? c.field }}: {{ shown(c.field, c.before) }} → <span class="text-ink">{{ shown(c.field, c.after) }}</span></p>
                </li>
            </ul>
        </section>
    </div>
</template>
