<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import PartyContactFields from '@/components/forms/PartyContactFields.vue';
import TextInput from '@/components/forms/TextInput.vue';
import ObjectPage from '@/components/object/ObjectPage.vue';
import type { AuditRow, StoredDocumentRow, TimelineEntry } from '@/components/object/types';
import DataTable from '@/components/table/DataTable.vue';
import DetailList from '@/components/table/DetailList.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { identityLabel } from '@/lib/lookupCreate';

/**
 * GA-17: everything about a customer on one object page (brief §6.2) — contact details and bank accounts, the policies they hold, their claims (on those
 * policies or paid to them), the receipts from them or allocated to their policies, documents and audit. Edit needs party.manage.
 */
interface PolicyRow { id: string; number: string | null; status: string; inception: string; expiry: string; product: string; gross_premium: string }
interface ClaimRow { id: string; number: string; status: string; loss_date: string; policy_number: string | null; reserve: string }
interface ReceiptRow { id: string; number: string; status: string; channel: string; value_date: string; amount: string }
const props = defineProps<{
    party: { id: string; kind: string; display_name: string; tax_id: string | null; roles: string[]; mobile: string | null; email: string | null; address: string | null; identity_no: string | null;
        date_of_birth: string | null; contact_person: string | null; status: string };
    bankAccounts: { id: string; bank_name: string; account_no_masked: string; is_default: boolean }[];
    policies: PolicyRow[];
    claims: ClaimRow[];
    receipts: ReceiptRow[];
    currency: string;
    mobileRequired: boolean;
    can: { manage: boolean };
    timeline?: TimelineEntry[];
    audit?: AuditRow[];
    documents?: StoredDocumentRow[];
    documentUpload: string | null;
}>();

const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const inForce = computed(() => props.policies.filter((p) => ['issued', 'active'].includes(p.status)).length);
const facts = computed(() => [
    { label: 'Kind', value: words(props.party.kind) },
    { label: 'Mobile', value: props.party.mobile ?? 'Not recorded' },
    { label: props.party.kind === 'organization' ? 'BRN' : 'NID', value: props.party.identity_no ?? '—' },
    { label: 'Policies in force', value: String(inForce.value), num: true },
]);
const editing = ref(false);
const edit = useForm({ kind: props.party.kind, display_name: props.party.display_name, tax_id: props.party.tax_id ?? '', mobile: props.party.mobile ?? '', email: props.party.email ?? '',
    address: props.party.address ?? '', identity_no: props.party.identity_no ?? '', date_of_birth: props.party.date_of_birth ?? '', contact_person: props.party.contact_person ?? '' });
const bank = useForm({ bank_name: '', account_number: '', is_default: false });
const adding = ref(false);

const policyColumns: DataColumn<PolicyRow>[] = [
    { id: 'number', header: 'Policy', value: (p) => p.number ?? 'Quote', href: (p) => `/policies/${p.id}`, width: 190 },
    { id: 'product', header: 'Product', value: (p) => p.product, width: 200, muted: true },
    { id: 'inception', header: 'Starts', type: 'date', value: (p) => p.inception },
    { id: 'expiry', header: 'Ends', type: 'date', value: (p) => p.expiry },
    { id: 'premium', header: 'Gross premium', type: 'money', value: (p) => p.gross_premium, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (p) => p.status },
];
const claimColumns: DataColumn<ClaimRow>[] = [
    { id: 'number', header: 'Claim', value: (c) => c.number, href: (c) => `/claims/${c.id}`, width: 190 },
    { id: 'policy', header: 'Policy', value: (c) => c.policy_number, width: 190, muted: true },
    { id: 'loss_date', header: 'Date of loss', type: 'date', value: (c) => c.loss_date },
    { id: 'reserve', header: 'Reserve', type: 'money', value: (c) => c.reserve, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (c) => c.status },
];
const receiptColumns: DataColumn<ReceiptRow>[] = [
    { id: 'number', header: 'Receipt', value: (r) => r.number, href: (r) => `/receipts/${r.id}`, width: 190 },
    { id: 'value_date', header: 'Value date', type: 'date', value: (r) => r.value_date },
    { id: 'channel', header: 'Received by', value: (r) => words(r.channel), width: 140, muted: true },
    { id: 'amount', header: 'Amount', type: 'money', value: (r) => r.amount, total: true },
    { id: 'status', header: 'Status', type: 'status', value: (r) => r.status },
];
</script>

<template>
    <AppLayout :title="party.display_name">
        <ObjectPage
            :title="party.display_name"
            :subtitle="party.roles.map(words).join(', ')"
            :status="party.status"
            :facts="facts"
            :crumbs="[{ label: 'Parties', href: '/parties' }]"
            :currency="currency"
            :timeline="timeline"
            :audit="audit"
            :documents="documents"
            :document-upload="documentUpload"
            :extra-tabs="[{ value: 'policies', label: `Policies (${policies.length})` }, { value: 'claims', label: `Claims (${claims.length})` }, { value: 'receipts', label: `Receipts (${receipts.length})` }]"
            :hidden-tabs="['accounting']"
        >
            <template #actions>
                <button v-if="can.manage" type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="editing = true">Edit details</button>
            </template>
            <template #overview>
                <div class="grid max-w-[1000px] gap-8 lg:grid-cols-2">
                    <section>
                        <h2 class="mb-2 text-ui font-medium">Contact and identity</h2>
                        <p v-if="!party.mobile && party.kind === 'individual'" class="mb-3 text-ui text-warn" role="status">
                            No mobile number: renewal and claim messages cannot reach this customer.
                            <button v-if="can.manage" type="button" class="text-accent-text hover:underline" @click="editing = true">Add it</button>
                        </p>
                        <DetailList :items="[{ label: 'Mobile', value: party.mobile }, { label: 'Email', value: party.email }, { label: 'Address', value: party.address },
                            { label: identityLabel(party.kind), value: party.identity_no }, { label: 'Tax ID (TIN)', value: party.tax_id },
                            party.kind === 'individual' ? { label: 'Date of birth', value: formatDate(party.date_of_birth) } : { label: 'Contact person', value: party.contact_person }]" />
                    </section>
                    <section>
                        <div class="mb-2 flex items-center justify-between">
                            <h2 class="text-ui font-medium">Bank accounts</h2>
                            <button v-if="can.manage" type="button" class="text-ui text-accent-text hover:underline" @click="adding = true">Add bank account</button>
                        </div>
                        <ul class="grid gap-2 text-ui">
                            <li v-for="account in bankAccounts" :key="account.id" class="flex justify-between border-b border-line pb-1">
                                <span>{{ account.bank_name }} <span class="text-ink-2">{{ account.account_no_masked }}</span></span>
                                <span v-if="account.is_default" class="text-dense text-ok">Default</span>
                            </li>
                            <li v-if="bankAccounts.length === 0" class="text-ink-2">No bank accounts.</li>
                        </ul>
                    </section>
                </div>
            </template>
            <template #tab-policies>
                <DataTable id="party-policies" label="Policies" :columns="policyColumns" :rows="policies" :row-key="(p) => p.id" :currency="currency" :url-sync="false" empty-text="No policies held." />
                <Link v-if="policies.length === 0" href="/quotations/create" class="mt-3 inline-block text-ui text-accent-text hover:underline">New quote</Link>
            </template>
            <template #tab-claims>
                <DataTable id="party-claims" label="Claims" :columns="claimColumns" :rows="claims" :row-key="(c) => c.id" :currency="currency" :url-sync="false" empty-text="No claims." />
            </template>
            <template #tab-receipts>
                <DataTable id="party-receipts" label="Receipts" :columns="receiptColumns" :rows="receipts" :row-key="(r) => r.id" :currency="currency" :url-sync="false" empty-text="No receipts." />
            </template>
        </ObjectPage>

        <Drawer v-if="can.manage" v-model:open="editing" title="Edit details">
            <FormLayout submit-label="Save details" :dirty="edit.isDirty" :processing="edit.processing" :error="(edit.errors as Record<string, string>).form" @submit="edit.put(`/parties/${party.id}`, { preserveScroll: true, onSuccess: () => (editing = false) })" @cancel="editing = false">
                <Field id="edit_display_name" label="Name" :error="edit.errors.display_name"><TextInput id="edit_display_name" v-model="edit.display_name" /></Field>
                <Field id="edit_tax_id" label="Tax ID (TIN)" optional :error="edit.errors.tax_id"><TextInput id="edit_tax_id" v-model="edit.tax_id" /></Field>
                <PartyContactFields :form="edit" :errors="edit.errors" :mobile-required="mobileRequired" />
            </FormLayout>
        </Drawer>
        <Drawer v-if="can.manage" v-model:open="adding" title="Add bank account">
            <FormLayout submit-label="Add bank account" :dirty="bank.isDirty" :processing="bank.processing" :error="(bank.errors as Record<string, string>).form" @submit="bank.post(`/parties/${party.id}/bank-accounts`, { preserveScroll: true, onSuccess: () => { bank.reset(); adding = false; } })" @cancel="adding = false">
                <Field id="bank_name" label="Bank" :error="bank.errors.bank_name"><TextInput id="bank_name" v-model="bank.bank_name" /></Field>
                <Field id="account_number" label="Account number" :error="bank.errors.account_number"><TextInput id="account_number" v-model="bank.account_number" inputmode="numeric" /></Field>
                <label class="flex items-center gap-2 text-ui text-ink-2"><input v-model="bank.is_default" type="checkbox" class="size-4 accent-accent" /> Default account</label>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
