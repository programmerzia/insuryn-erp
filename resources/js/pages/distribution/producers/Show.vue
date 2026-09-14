<script setup lang="ts">
import { Deferred, Link, router, useForm } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import JournalPreviewDialog from '@/components/forms/JournalPreviewDialog.vue';
import MoneyInput from '@/components/forms/MoneyInput.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import AuditList from '@/components/object/AuditList.vue';
import DocumentList from '@/components/object/DocumentList.vue';
import SkeletonRows from '@/components/object/SkeletonRows.vue';
import type { AuditRow, StoredDocumentRow } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useBusinessToday } from '@/lib/businessToday';
import { formatDate, formatMoney } from '@/lib/format';
import { useMoneyForm } from '@/lib/moneyForm';

/** Distribution design note §6 producer page: Overview · Hierarchy · Compensation · Production · Statements · Documents · Audit. */
interface Licence { id: string; authority: string; licence_no: string; class: string; issued_on: string; expires_on: string; status: string; status_reason: string | null }
interface Entry { id: string; earned_on: string; kind: string; role: string; level: string | null; policy_number: string | null; policy_id: string | null; rate_percent: string | null; amount: string; withholding: string; status: string }
interface ProductionPeriod { from: string; to: string; premium: string; policies: number; collections: string; premium_target: string | null }
const props = defineProps<{
    producer: { id: string; code: string; name: string; type: string; status: string; party_id: string; joined_on: string | null; employee_id: string | null };
    on: string;
    facts: { label: string; value: string }[];
    licences: Licence[];
    advances: { id: string; issued_on: string; amount: string; balance: string; status: string; recovery: string }[];
    hierarchy: { chain: string[]; history: { from: string; to: string | null; parent_code: string | null; level: string | null }[]; team: { id: string; code: string; level_code: string | null }[] };
    compensation: { entries: Entry[]; exceptions: { occurred_on: string; reason_code: string; message: string }[] };
    production: { month: ProductionPeriod; quarter: ProductionPeriod; year: ProductionPeriod; persistency_13: string | null; persistency_25: string | null };
    statements: { id: string; number: string | null; period: string; net: string; status: string; paid_via: string; paid_on: string | null }[];
    parents: { id: string; code: string }[];
    levels: string[];
    bankAccounts: { id: string; bank_name: string; account_no_masked: string }[];
    can: { manage: boolean; advance: boolean };
    audit?: AuditRow[];
    /** Gap audit GA-41: agency agreements, KYC and licence certificates attached to the producer. */
    documents?: StoredDocumentRow[];
    documentUpload?: string | null;
}>();

const base = `/distribution/producers/${props.producer.id}`;
const initialTab = typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('tab') ?? 'overview') : 'overview';
const tab = ref(initialTab);
watch(tab, (value) => {
    const params = new URLSearchParams(window.location.search);
    if (value === 'overview') params.delete('tab');
    else params.set('tab', value);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${params.toString() ? `?${params}` : ''}`);
});
const tabs = [['overview', 'Overview'], ['hierarchy', 'Hierarchy'], ['compensation', 'Compensation'], ['production', 'Production'], ['statements', 'Statements'], ['documents', 'Documents'], ['audit', 'Audit']] as const;
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const routeWords: Record<string, string> = { bank: 'Bank', payroll: 'Payroll', ap: 'Accounts payable' };

const drawer = ref<'licence' | 'advance' | 'move' | 'status' | null>(null);
const done = () => (drawer.value = null);
const licence = useForm({ licence_no: '', class: 'life', issued_on: '', expires_on: '', authority: 'IDRA' });
// Gap fix GA-19: a move in the tree and an advance take effect today unless changed; licence dates come from the certificate and stay empty.
const today = useBusinessToday();
const move = useForm({ producer_id: props.producer.id, parent_id: '', level_code: '', effective_from: today });
const status = useForm({ status: props.producer.status });
const advance = useMoneyForm(() => `${base}/advances`, { amount: '', issued_on: today, recovery: 'percent_of_net', recovery_percent: '50', bank_account_id: '' }, done);
</script>

<template>
    <AppLayout :title="`${producer.code} ${producer.name}`">
        <div class="flex min-h-full flex-col">
            <header class="border-b border-line px-6 pt-3">
                <Breadcrumb :base="[{ label: 'Producers', href: '/distribution/producers' }]" />
                <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pb-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <h1 class="text-title font-semibold">{{ producer.code }} · {{ producer.name }}</h1>
                            <StatusBadge :status="producer.status" />
                        </div>
                        <p class="text-ui text-ink-2">Joined {{ producer.joined_on ? formatDate(producer.joined_on) : '—' }}<template v-if="producer.employee_id"> · on payroll</template></p>
                    </div>
                    <dl class="flex flex-wrap gap-x-8 gap-y-1">
                        <div v-for="fact in facts" :key="fact.label">
                            <dt class="text-dense text-ink-2">{{ fact.label }}</dt>
                            <dd class="text-ui font-medium">{{ fact.value }}</dd>
                        </div>
                    </dl>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <Button v-if="can.manage" variant="secondary" @click="drawer = 'status'">Change status</Button>
                        <Button v-if="can.advance" variant="secondary" @click="drawer = 'advance'">Issue advance</Button>
                        <Button v-if="can.manage" variant="primary" @click="drawer = 'licence'">Record licence</Button>
                    </div>
                </div>
            </header>
            <FormBanner />
            <TabsRoot v-model="tab" class="flex flex-1 flex-col">
                <TabsList class="flex gap-5 border-b border-line px-6" aria-label="Sections">
                    <TabsTrigger v-for="[value, label] in tabs" :key="value" :value="value" class="-mb-px h-9 border-b-2 border-transparent text-ui text-ink-2 hover:text-ink data-[state=active]:border-accent data-[state=active]:text-ink">
                        {{ label }}
                    </TabsTrigger>
                </TabsList>
                <div class="flex-1 px-6 py-4">
                    <TabsContent value="overview" class="grid max-w-[1100px] gap-6 outline-none lg:grid-cols-2">
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Licences</h2>
                            <ul class="border border-line">
                                <li v-for="l in licences" :key="l.id" class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                    <span class="font-medium">{{ l.authority }} {{ l.licence_no }}</span>
                                    <span class="text-ink-2">{{ words(l.class) }} · {{ formatDate(l.issued_on) }} to {{ formatDate(l.expires_on) }}</span>
                                    <StatusBadge :status="l.status" class="ml-auto" />
                                </li>
                                <li v-if="licences.length === 0" class="px-3 py-4 text-ui text-ink-2">No licences. New business is refused until one is recorded.</li>
                            </ul>
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Advances</h2>
                            <ul class="border border-line">
                                <li v-for="a in advances" :key="a.id" class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                    <span class="w-28 font-medium tabular-nums">{{ formatMoney(a.balance) }}</span>
                                    <span class="text-ink-2">of {{ formatMoney(a.amount) }} issued {{ formatDate(a.issued_on) }} · {{ a.recovery }}</span>
                                    <StatusBadge :status="a.status" class="ml-auto" />
                                </li>
                                <li v-if="advances.length === 0" class="px-3 py-4 text-ui text-ink-2">No advances.</li>
                            </ul>
                        </section>
                    </TabsContent>

                    <TabsContent value="hierarchy" class="grid max-w-[1100px] gap-6 outline-none lg:grid-cols-2">
                        <section>
                            <div class="mb-2 flex items-center justify-between">
                                <h2 class="text-ui font-medium">Reports to, on {{ formatDate(on) }}</h2>
                                <Button v-if="can.manage" variant="secondary" size="sm" @click="drawer = 'move'">Transfer</Button>
                            </div>
                            <p class="text-ui">{{ hierarchy.chain.join(' → ') }}</p>
                            <h2 class="mt-6 mb-2 text-ui font-medium">Team</h2>
                            <ul class="border border-line">
                                <li v-for="m in hierarchy.team" :key="m.id" class="flex items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                    <Link :href="`/distribution/producers/${m.id}`" class="text-accent-text hover:underline">{{ m.code }}</Link><span class="text-ink-2">{{ m.level_code ?? 'No level' }}</span>
                                </li>
                                <li v-if="hierarchy.team.length === 0" class="px-3 py-4 text-ui text-ink-2">Nobody reports to {{ producer.code }}.</li>
                            </ul>
                        </section>
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Positions held</h2>
                            <div class="overflow-x-auto border border-line">
                                <table class="w-full border-separate border-spacing-0 text-dense">
                                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">From</th><th class="border-b border-line px-3 text-left font-medium">Until</th><th class="border-b border-line px-3 text-left font-medium">Reports to</th><th class="border-b border-line px-3 text-left font-medium">Level</th></tr></thead>
                                    <tbody><tr v-for="(h, i) in hierarchy.history" :key="i" class="h-(--row-h)"><td class="border-b border-line px-3">{{ formatDate(h.from) }}</td><td class="border-b border-line px-3">{{ h.to ? formatDate(h.to) : 'Now' }}</td><td class="border-b border-line px-3">{{ h.parent_code ?? 'Nobody' }}</td><td class="border-b border-line px-3">{{ h.level ?? '—' }}</td></tr></tbody>
                                </table>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="compensation" class="grid max-w-[1200px] gap-6 outline-none">
                        <section>
                            <h2 class="mb-2 text-ui font-medium">Commission entries</h2>
                            <div class="overflow-x-auto border border-line">
                                <table class="w-full border-separate border-spacing-0 text-dense">
                                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)">
                                        <th class="border-b border-line px-3 text-left font-medium">Date</th><th class="border-b border-line px-3 text-left font-medium">Kind</th><th class="border-b border-line px-3 text-left font-medium">Policy</th>
                                        <th class="border-b border-line px-3 text-right font-medium">Rate (%)</th><th class="border-b border-line px-3 text-right font-medium">Amount (BDT)</th><th class="border-b border-line px-3 text-right font-medium">Withheld</th><th class="border-b border-line px-3 text-left font-medium">Status</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="e in compensation.entries" :key="e.id" class="h-(--row-h)">
                                            <td class="border-b border-line px-3">{{ formatDate(e.earned_on) }}</td>
                                            <td class="border-b border-line px-3">{{ e.kind === 'earned' ? (e.role === 'override' ? `Override ${e.level ?? ''}` : 'Direct') : words(e.kind) }}</td>
                                            <td class="border-b border-line px-3"><Link v-if="e.policy_id" :href="`/policies/${e.policy_id}`" class="text-accent-text hover:underline">{{ e.policy_number }}</Link></td>
                                            <td class="num border-b border-line px-3">{{ e.rate_percent }}</td><td class="num border-b border-line px-3">{{ formatMoney(e.amount) }}</td><td class="num border-b border-line px-3">{{ formatMoney(e.withholding) }}</td>
                                            <td class="border-b border-line px-3"><StatusBadge :status="e.status" /></td>
                                        </tr>
                                        <tr v-if="compensation.entries.length === 0"><td colspan="7" class="px-3 py-4 text-ui text-ink-2">No commission yet.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section v-if="compensation.exceptions.length">
                            <h2 class="mb-2 text-ui font-medium">Not paid, and why</h2>
                            <ul class="border border-line">
                                <li v-for="(x, i) in compensation.exceptions" :key="i" class="flex gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0"><span class="w-24 shrink-0 text-ink-2">{{ formatDate(x.occurred_on) }}</span><span>{{ x.message }}</span></li>
                            </ul>
                        </section>
                    </TabsContent>

                    <TabsContent value="production" class="max-w-[900px] outline-none">
                        <div class="overflow-x-auto border border-line">
                            <table class="w-full border-separate border-spacing-0 text-dense">
                                <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="border-b border-line px-3 text-left font-medium">Period</th><th class="border-b border-line px-3 text-right font-medium">Written premium (BDT)</th><th class="border-b border-line px-3 text-right font-medium">Target</th><th class="border-b border-line px-3 text-right font-medium">Policies</th><th class="border-b border-line px-3 text-right font-medium">Collections (BDT)</th></tr></thead>
                                <tbody>
                                    <tr v-for="[key, label] in [['month', 'This month'], ['quarter', 'This quarter'], ['year', 'This year']] as const" :key="key" class="h-(--row-h)">
                                        <td class="border-b border-line px-3">{{ label }} <span class="text-ink-2">({{ formatDate(production[key].from) }} to {{ formatDate(production[key].to) }})</span></td>
                                        <td class="num border-b border-line px-3">{{ formatMoney(production[key].premium) }}</td><td class="num border-b border-line px-3 text-ink-2">{{ production[key].premium_target ? formatMoney(production[key].premium_target) : '—' }}</td>
                                        <td class="num border-b border-line px-3">{{ production[key].policies }}</td><td class="num border-b border-line px-3">{{ formatMoney(production[key].collections) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-4 text-ui">13th-month persistency <span class="font-medium tabular-nums">{{ production.persistency_13 ? `${production.persistency_13}%` : 'not measurable yet' }}</span> · 25th-month <span class="font-medium tabular-nums">{{ production.persistency_25 ? `${production.persistency_25}%` : 'not measurable yet' }}</span></p>
                    </TabsContent>

                    <TabsContent value="statements" class="max-w-[900px] outline-none">
                        <ul class="border border-line">
                            <li v-for="s in statements" :key="s.id" class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 text-ui last:border-b-0">
                                <span class="w-36 font-medium">{{ s.number ?? 'Draft' }}</span><span class="text-ink-2">{{ formatDate(s.period) }}</span><span class="w-28 text-right tabular-nums">{{ formatMoney(s.net) }}</span>
                                <span class="text-ink-2">{{ routeWords[s.paid_via] }}</span><StatusBadge :status="s.status" class="ml-auto" />
                            </li>
                            <li v-if="statements.length === 0" class="px-3 py-4 text-ui text-ink-2">No statements yet. They are prepared in the statement run.</li>
                        </ul>
                        <Link href="/distribution/statements" class="mt-3 inline-block text-ui text-accent-text hover:underline">Open the statement run</Link>
                    </TabsContent>

                    <TabsContent value="documents" class="outline-none">
                        <Deferred data="documents"><template #fallback><SkeletonRows /></template><DocumentList :documents="documents ?? []" :upload-url="documentUpload ?? null" /></Deferred>
                    </TabsContent>
                    <TabsContent value="audit" class="outline-none">
                        <Deferred data="audit"><template #fallback><SkeletonRows /></template><AuditList :rows="audit ?? []" /></Deferred>
                    </TabsContent>
                </div>
            </TabsRoot>
        </div>

        <Drawer :open="drawer === 'licence'" title="Record a licence" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Record licence" :dirty="licence.isDirty" :processing="licence.processing" :error="(licence.errors as Record<string, string>).form" @submit="licence.post(`${base}/licences`, { preserveScroll: true, onSuccess: () => { licence.reset(); done(); } })" @cancel="done">
                <Field id="authority" label="Authority" :error="licence.errors.authority"><TextInput v-model="licence.authority" /></Field>
                <Field id="licence_no" label="Licence number" :error="licence.errors.licence_no"><TextInput v-model="licence.licence_no" /></Field>
                <Field id="licence_class" label="Class" :error="licence.errors.class"><SelectInput id="licence_class" v-model="licence.class" :options="[{ value: 'life', label: 'Life' }, { value: 'non_life', label: 'Non-life' }, { value: 'both', label: 'Life and non-life' }]" /></Field>
                <Field id="issued_on" label="Issued on" :error="licence.errors.issued_on"><DateInput v-model="licence.issued_on" /></Field>
                <Field id="expires_on" label="Expires on" hint="Alerts go out 60, 30 and 7 days before." :error="licence.errors.expires_on"><DateInput v-model="licence.expires_on" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'move'" title="Transfer in the hierarchy" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Transfer" :dirty="move.isDirty" :processing="move.processing" :error="(move.errors as Record<string, string>).form" @submit="move.post('/distribution/hierarchy/moves', { preserveScroll: true, onSuccess: done })" @cancel="done">
                <Field id="parent_id" label="Reports to" optional :error="move.errors.parent_id"><SelectInput id="parent_id" v-model="move.parent_id" placeholder="Nobody" :options="parents.map((p) => ({ value: p.id, label: p.code }))" /></Field>
                <Field id="level_code" label="Level" optional :error="move.errors.level_code"><SelectInput id="level_code" v-model="move.level_code" placeholder="No level" :options="levels.map((l) => ({ value: l, label: l }))" /></Field>
                <Field id="effective_from" label="From" hint="Payouts before this date keep the old tree." :error="move.errors.effective_from"><DateInput v-model="move.effective_from" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'advance'" title="Issue an advance" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Review and pay" :dirty="advance.form.isDirty" :processing="advance.form.processing" :error="(advance.form.errors as Record<string, string>).form" @submit="advance.review" @cancel="done">
                <Field id="advance_amount" label="Amount (BDT)" :error="advance.form.errors.amount"><MoneyInput v-model="advance.form.amount" /></Field>
                <Field id="advance_issued_on" label="Paid on" :error="advance.form.errors.issued_on"><DateInput v-model="advance.form.issued_on" /></Field>
                <Field id="recovery" label="Recover" :error="advance.form.errors.recovery"><SelectInput id="recovery" v-model="advance.form.recovery" :options="[{ value: 'percent_of_net', label: 'A share of each statement' }, { value: 'full', label: 'In full from the next statements' }]" /></Field>
                <Field v-if="advance.form.recovery === 'percent_of_net'" id="recovery_percent" label="Share of each statement's net (%)" :error="advance.form.errors.recovery_percent"><TextInput v-model="advance.form.recovery_percent" inputmode="decimal" /></Field>
                <Field id="advance_bank" label="Pay from" optional :error="advance.form.errors.bank_account_id"><SelectInput id="advance_bank" v-model="advance.form.bank_account_id" placeholder="Default bank account" :options="bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no_masked}` }))" /></Field>
            </FormLayout>
        </Drawer>
        <Drawer :open="drawer === 'status'" title="Change status" @update:open="(o) => !o && done()">
            <FormLayout submit-label="Save status" :dirty="status.isDirty" :processing="status.processing" :error="(status.errors as Record<string, string>).form" @submit="status.post(`${base}/status`, { preserveScroll: true, onSuccess: done })" @cancel="done">
                <Field id="producer_status" label="Status" hint="Suspended producers write no new business and earn no commission." :error="status.errors.status">
                    <SelectInput id="producer_status" v-model="status.status" :options="['applicant', 'active', 'suspended'].map((s) => ({ value: s, label: words(s) }))" />
                </Field>
            </FormLayout>
        </Drawer>
        <JournalPreviewDialog v-model:open="advance.previewOpen.value" :result="advance.preview.value" :title="`Pay an advance to ${producer.code}?`" confirm-label="Pay the advance" currency="BDT" :processing="advance.form.processing" @confirm="advance.post" />
    </AppLayout>
</template>
