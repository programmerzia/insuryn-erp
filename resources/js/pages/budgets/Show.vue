<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinor, parseMoney } from '@/lib/money';
import { formatMoney } from '@/lib/format';

/**
 * Design addendum v2 §B.8.1 budget grid: income and expense accounts × fiscal months for one branch at a time. Amounts are typed in, pasted from a spreadsheet
 * (tab-separated: account code, optional name, twelve months) or copied from last year's actuals ± %. A draft is sent for approval; someone else approves.
 */
interface Account { id: string; code: string; name: string; type: string }
const props = defineProps<{
    budget: { id: string; year: string; code: string; name: string; version: number; status: string; note: string | null; prepared_by: string; total: string; branch_totals: { branch: string; total: string }[] };
    months: string[];
    branches: { id: string; label: string }[];
    branchId: string;
    accounts: Account[];
    grid: { account_id: string; amounts: string[] }[];
    can: { edit: boolean; submit: boolean; decide: boolean; revise: boolean };
}>();

const rows = reactive<{ account_id: string; amounts: string[] }[]>([]);
function load(): void {
    rows.splice(0, rows.length, ...props.grid.map((r) => ({ account_id: r.account_id, amounts: [...r.amounts] })));
}
load();
watch(() => props.grid, load);
const account = (id: string) => props.accounts.find((a) => a.id === id);
const sorted = computed(() => [...rows].sort((a, b) => (account(a.account_id)?.code ?? '').localeCompare(account(b.account_id)?.code ?? '')));
const unused = computed(() => props.accounts.filter((a) => !rows.some((r) => r.account_id === a.id)));
const adding = ref('');
const dirty = ref(false);
const saving = ref(false);
const pasting = ref(false);
const copying = ref(false);
const returning = ref(false);
const paste = useForm({ branch_id: props.branchId, text: '' });
const copy = useForm({ percent: '5' });
const back = useForm({ reason: '' });

const total = (amounts: string[]) => formatMinor(amounts.reduce((sum, a) => sum + (parseMoney(a) ?? 0n), 0n));
const monthTotal = (index: number) => formatMinor(rows.reduce((sum, r) => sum + (parseMoney(r.amounts[index] ?? '') ?? 0n), 0n));
const grand = computed(() => formatMinor(rows.reduce((sum, r) => sum + r.amounts.reduce((s, a) => s + (parseMoney(a) ?? 0n), 0n), 0n)));

function addAccount(): void {
    if (!adding.value) return;
    rows.push({ account_id: adding.value, amounts: Array(12).fill('') });
    adding.value = '';
    dirty.value = true;
}
function fillRight(row: { amounts: string[] }, index: number): void {
    const value = row.amounts[index] ?? '';
    for (let i = index + 1; i < 12; i++) row.amounts[i] = value;
    dirty.value = true;
}
function save(): void {
    router.post(`/budgets/${props.budget.id}/lines`, { branch_id: props.branchId, rows: rows.map((r) => ({ account_id: r.account_id, amounts: r.amounts })) }, {
        preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false), onSuccess: () => (dirty.value = false),
    });
}
function branch(id: string | undefined): void {
    if (id && id !== props.branchId) router.get(`/budgets/${props.budget.id}`, { branch: id });
}
const act = (action: string) => router.post(`/budgets/${props.budget.id}/${action}`, {}, { preserveScroll: true });
</script>

<template>
    <AppLayout help="budgets" :title="`${budget.name} ${budget.year}`" fill>
        <div class="border-b border-line px-4 pt-2"><Breadcrumb :base="[{ label: 'Budgets', href: '/budgets' }]" /></div>
        <header class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2">
            <h1 class="text-section font-semibold">{{ budget.name }} · {{ budget.year }} · version {{ budget.version }}</h1>
            <StatusBadge :status="budget.status" />
            <SelectInput :model-value="branchId" :options="branches.map((b) => ({ value: b.id, label: b.label }))" class="w-auto" aria-label="Branch" @update:model-value="branch" />
            <span class="text-ui text-ink-2">Total {{ formatMoney(budget.total) }} BDT<template v-for="t in budget.branch_totals" :key="t.branch"> · {{ t.branch }} {{ formatMoney(t.total) }}</template></span>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <template v-if="can.edit">
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="pasting = true">Paste from spreadsheet</button>
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="copying = true">Copy last year's actuals</button>
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="!dirty || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
                </template>
                <button v-if="can.submit" type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" :disabled="dirty" @click="act('submit')">Send for approval</button>
                <template v-if="can.decide">
                    <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="returning = true">Return to draft</button>
                    <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="act('approve')">Approve</button>
                </template>
                <button v-if="can.revise" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="act('revise')">New version</button>
                <Link href="/budgets/variance" class="text-ui text-accent-text hover:underline">Budget variance</Link>
            </div>
        </header>
        <p v-if="budget.status === 'draft' && budget.note" class="border-b border-line bg-surface-2 px-4 py-2 text-ui">Returned: {{ budget.note }}</p>
        <p v-if="budget.status === 'submitted' && !can.decide" class="border-b border-line bg-surface-2 px-4 py-2 text-ui text-ink-2">Waiting for approval. Prepared by {{ budget.prepared_by }}; someone else approves it.</p>
        <div class="min-h-0 flex-1 overflow-auto">
            <table class="border-separate border-spacing-0 text-dense">
                <thead class="sticky top-0 z-10 bg-surface-2 text-ink-2">
                    <tr class="h-(--row-h)">
                        <th class="sticky left-0 z-20 min-w-[260px] border-b border-r border-line bg-surface-2 px-3 text-left font-medium">Account</th>
                        <th v-for="m in months" :key="m" class="min-w-[104px] border-b border-line px-2 text-right font-medium">{{ m }}</th>
                        <th class="min-w-[120px] border-b border-l border-line px-3 text-right font-medium">Year (BDT)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in sorted" :key="row.account_id" class="h-(--row-h)">
                        <td class="sticky left-0 border-b border-r border-line bg-surface px-3 whitespace-nowrap">{{ account(row.account_id)?.code }} {{ account(row.account_id)?.name }}</td>
                        <td v-for="(_, i) in months" :key="i" class="border-b border-line px-1">
                            <input v-if="can.edit" v-model="row.amounts[i]" class="num h-7 w-full rounded-control border border-transparent bg-transparent px-1 text-right hover:border-line-control focus:border-accent" inputmode="decimal"
                                :aria-label="`${account(row.account_id)?.name} ${months[i]}`" @input="dirty = true" @keydown.ctrl.enter.prevent="fillRight(row, i)" />
                            <span v-else class="num block px-1">{{ formatMoney(row.amounts[i]) }}</span>
                        </td>
                        <td class="num border-b border-l border-line px-3 font-medium">{{ total(row.amounts) }}</td>
                    </tr>
                    <tr v-if="rows.length === 0"><td :colspan="months.length + 2" class="px-3 py-6 text-center text-ui text-ink-2">No amounts for this branch yet.</td></tr>
                </tbody>
                <tfoot class="sticky bottom-0 bg-surface-2 font-medium">
                    <tr class="h-(--row-h)">
                        <td class="sticky left-0 border-r border-line bg-surface-2 px-3">
                            <div v-if="can.edit" class="flex items-center gap-1">
                                <select v-model="adding" class="h-7 max-w-[190px] rounded-control border border-line-control bg-surface px-1 text-dense font-normal" aria-label="Add an account">
                                    <option value="">Add an account…</option>
                                    <option v-for="a in unused" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option>
                                </select>
                                <button type="button" class="h-7 rounded-control border border-line-control px-2 font-normal" @click="addAccount">Add</button>
                            </div>
                            <template v-else>Total</template>
                        </td>
                        <td v-for="(_, i) in months" :key="i" class="num px-2">{{ monthTotal(i) }}</td>
                        <td class="num border-l border-line px-3">{{ grand }}</td>
                    </tr>
                </tfoot>
            </table>
            <p v-if="can.edit" class="px-4 py-2 text-dense text-ink-2">Ctrl+Enter in a month copies its amount to the months after it.</p>
        </div>
        <Drawer v-model:open="pasting" title="Paste from spreadsheet" width="w-[620px]">
            <FormLayout submit-label="Paste" :dirty="paste.isDirty" :processing="paste.processing" :error="(paste.errors as Record<string, string>).form" @submit="paste.transform((d) => ({ ...d, branch_id: branchId })).post(`/budgets/${budget.id}/paste`, { preserveScroll: true, onSuccess: () => { pasting = false; paste.reset(); } })" @cancel="pasting = false">
                <p class="text-ui text-ink-2">Copy the rows from Excel: the account code, optionally its name, then twelve monthly amounts from {{ months[0] }} to {{ months[11] }}. They replace this branch's amounts for those accounts.</p>
                <Field id="text" label="Rows" :error="paste.errors.text">
                    <textarea id="text" v-model="paste.text" rows="14" class="w-full rounded-control border border-line-control bg-surface p-2 font-mono text-dense" placeholder="5300&#9;Salaries&#9;850000&#9;850000&#9;…" />
                </Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="copying" title="Copy last year's actuals">
            <FormLayout submit-label="Copy actuals" :dirty="true" :processing="copy.processing" :error="(copy.errors as Record<string, string>).form" @submit="copy.post(`/budgets/${budget.id}/copy-actuals`, { preserveScroll: true, onSuccess: () => (copying = false) })" @cancel="copying = false">
                <p class="text-ui text-ink-2">Last year's posted income and expense per account, branch and month, changed by a percentage, replace the draft's amounts for those accounts in every branch.</p>
                <Field id="percent" label="Change (%)" hint="8 for 8% more, -2.5 for 2.5% less." :error="copy.errors.percent"><TextInput v-model="copy.percent" inputmode="decimal" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer v-model:open="returning" :title="`Return ${budget.name} ${budget.year} to draft`">
            <FormLayout submit-label="Return to draft" :dirty="back.isDirty" :processing="back.processing" :error="(back.errors as Record<string, string>).form" @submit="back.post(`/budgets/${budget.id}/return`, { onSuccess: () => (returning = false) })" @cancel="returning = false">
                <Field id="reason" label="What to change" :error="back.errors.reason"><TextInput v-model="back.reason" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
