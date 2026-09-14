<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ACCOUNT_TYPES, ASK_FOR_ACCOUNT, normalSideFor } from '@/lib/accountCreate';
import { type ChartAccount, deactivateBlocker, editLocks, newAccountDraft, parentOptions, postingLabel } from '@/lib/chartOfAccounts';
import { confirmAction } from '@/lib/confirm';
import { formatMinor } from '@/lib/money';

/**
 * UX U2: the chart of accounts as an indented tree by code. Holders of accounting.manage_coa add an account in a drawer (the import's rules), change its
 * name, parent, postability, type and side (type and side stay once journal lines name it), and deactivate it when nothing is left on it.
 */
const props = defineProps<{
    entity: { id: string; code: string; name: string; currency: string };
    accounts: ChartAccount[];
    canManage: boolean;
    types: string[];
    subledgers: string[];
}>();

const active = ref<string | null>(null);
const editing = ref<ChartAccount | null>(null);
const drawerOpen = ref(false);
const sides = [{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }];
const typeLabel = (type: string) => ACCOUNT_TYPES.find((t) => t.value === type)?.label ?? type;
const form = useForm(newAccountDraft());
const locks = computed(() => (editing.value ? editLocks(editing.value) : { typeAndSide: false, postable: false }));
const parents = computed(() => parentOptions(props.accounts, editing.value?.id ?? null));
const subledgerOptions = computed(() => props.subledgers.map((s) => ({ value: s, label: s.charAt(0).toUpperCase() + s.slice(1) })));

function openNew(parent: ChartAccount | null = null): void {
    editing.value = null;
    form.defaults(newAccountDraft(parent));
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}

function openEdit(account: ChartAccount): void {
    editing.value = account;
    form.defaults({ code: account.code, name: account.name, type: account.type, normal_side: account.normal_side, parent_id: account.parent_id ?? '', is_postable: account.is_postable,
        is_control: account.is_control, control_subledger: account.control_subledger ?? '', currency: account.currency ?? '' });
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}

function chooseType(type: string | undefined): void {
    form.type = type ?? form.type;
    form.normal_side = normalSideFor(form.type);
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };
    if (editing.value) {
        form.transform((d) => ({ name: d.name, type: d.type, normal_side: d.normal_side, parent_id: d.parent_id || null, is_postable: d.is_postable }))
            .put(`/accounting/chart-of-accounts/${editing.value.id}`, options);
    } else {
        form.transform((d) => ({ ...d, parent_id: d.parent_id || null, control_subledger: d.is_control ? d.control_subledger : null, currency: d.currency.trim().toUpperCase() || null }))
            .post('/accounting/chart-of-accounts', options);
    }
}

async function deactivate(account: ChartAccount): Promise<void> {
    const ok = await confirmAction({ title: `Deactivate ${account.code} ${account.name}?`, body: 'Journals can no longer post to it. Its history stays, and you can reactivate it later.', confirmLabel: 'Deactivate', tone: 'danger' });
    if (ok) router.post(`/accounting/chart-of-accounts/${account.id}/deactivate`, {}, { preserveScroll: true });
}

function reactivate(account: ChartAccount): void {
    router.post(`/accounting/chart-of-accounts/${account.id}/reactivate`, {}, { preserveScroll: true });
}

const columns: DataColumn<ChartAccount>[] = [
    { id: 'code', header: 'Code', value: (a) => a.code, width: 72 },
    { id: 'name', header: 'Name', value: (a) => a.name, width: 240 },
    { id: 'type', header: 'Type', value: (a) => typeLabel(a.type), width: 88, filterOptions: ACCOUNT_TYPES.map((t) => t.label) },
    { id: 'side', header: 'Normal side', value: (a) => (a.normal_side === 'debit' ? 'Debit' : 'Credit'), width: 88 },
    { id: 'posting', header: 'Posting', value: (a) => postingLabel(a), width: 160 },
    { id: 'roles', header: 'Used by the accounting for', value: (a) => a.roles.map((r) => r.description).join(', '), width: 200, muted: true },
    { id: 'currency', header: 'Currency', value: (a) => a.currency ?? 'Any', width: 72 },
    { id: 'balance', header: 'Balance', type: 'money', value: (a) => formatMinor(BigInt(a.balance_minor), { parentheses: true }), width: 120 },
    { id: 'status', header: 'Status', type: 'status', value: (a) => a.status, filterOptions: ['active', 'inactive'] },
];
</script>

<template>
    <AppLayout title="Chart of accounts" fill>
        <QueueView
            id="accounting-chart-of-accounts"
            v-model:active="active"
            title="Chart of accounts"
            :columns="columns"
            :rows="accounts"
            :row-key="(a) => a.id"
            :currency="entity.currency"
            :action="canManage ? { label: 'Add account' } : null"
            :empty-text="canManage ? 'No accounts yet: add the first one, or import a chart from a file.' : 'No accounts yet: the Finance Manager adds them.'"
            :inspector-title="(a) => `${a.code} · ${a.name}`"
            :inspector-subtitle="(a) => `${typeLabel(a.type)}, ${a.normal_side} side`"
            @action="openNew()"
        >
            <template #toolbar>
                <Link v-if="canManage" href="/accounting/imports" class="ml-2 inline-flex h-8 items-center px-2 text-ui text-accent-text hover:underline">Import from CSV</Link>
                <Link href="/accounting/account-roles" class="inline-flex h-8 items-center px-2 text-ui text-accent-text hover:underline">Account roles</Link>
            </template>
            <template #cell-name="{ row }">
                <span class="block truncate" :style="{ paddingInlineStart: `${row.depth * 16}px` }" :class="row.is_postable ? '' : 'font-medium'">{{ row.name }}</span>
            </template>
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Code', value: row.code },
                        { label: 'Under', value: row.parent_code ? `${row.parent_code} · ${accounts.find((a) => a.id === row.parent_id)?.name ?? ''}` : 'Top level' },
                        { label: 'Type', value: typeLabel(row.type) },
                        { label: 'Normal side', value: row.normal_side === 'debit' ? 'Debit' : 'Credit' },
                        { label: 'Posting', value: postingLabel(row) },
                        { label: 'Currency', value: row.currency ?? `Any (books kept in ${entity.currency})` },
                        { label: 'Balance', value: `${formatMinor(BigInt(row.balance_minor), { parentheses: true })} ${entity.currency}`, num: true },
                        { label: 'Status', value: row.status === 'active' ? 'Active' : 'Inactive' },
                    ]"
                />
                <h3 class="mt-6 mb-1 text-ui font-medium">Used by the accounting for</h3>
                <ul v-if="row.roles.length" class="grid gap-1 text-dense">
                    <li v-for="r in row.roles" :key="r.code">{{ r.description }}</li>
                </ul>
                <p v-else class="text-dense text-ink-2">No account role posts here. Journals you write can.</p>
                <Link href="/accounting/account-roles" class="mt-1 inline-block text-dense text-accent-text hover:underline">{{ row.roles.length ? 'Change in Account roles' : 'Map a role in Account roles' }}</Link>

                <div v-if="canManage" class="mt-6 grid gap-2">
                    <div class="flex flex-wrap gap-2">
                        <Button variant="secondary" @click="openEdit(row)">Edit</Button>
                        <Button variant="secondary" @click="openNew(row)">Add account under it</Button>
                        <Button v-if="row.status !== 'active'" variant="secondary" @click="reactivate(row)">Reactivate</Button>
                        <Button v-else variant="danger" :disabled="deactivateBlocker(row) !== null" @click="deactivate(row)">Deactivate</Button>
                    </div>
                    <p v-if="deactivateBlocker(row)" class="text-dense text-ink-2">Deactivate is off: {{ deactivateBlocker(row) }}</p>
                </div>
                <p v-else class="mt-6 text-dense text-ink-2">{{ ASK_FOR_ACCOUNT }}</p>
            </template>
        </QueueView>

        <Drawer v-model:open="drawerOpen" :title="editing ? `Edit ${editing.code} · ${editing.name}` : 'Add account'" width="w-[480px]">
            <p class="mb-4 text-ui text-ink-2">
                {{ editing ? 'The code stays. Type and normal side stay once a journal line uses the account.' : 'Pick the type first: the normal side follows it. Put the account under a heading so reports group it.' }}
            </p>
            <FormLayout :submit-label="editing ? 'Save account' : 'Add account'" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="save" @cancel="drawerOpen = false">
                <div class="grid grid-cols-[120px_minmax(0,1fr)] gap-3">
                    <Field id="coa-code" label="Code" :hint="editing ? undefined : 'Unique in the chart, e.g. 6150.'" :error="form.errors.code">
                        <TextInput id="coa-code" v-model="form.code" :maxlength="32" :disabled="!!editing" />
                    </Field>
                    <Field id="coa-name" label="Name" :error="form.errors.name"><TextInput id="coa-name" v-model="form.name" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field id="coa-type" label="Type" :error="form.errors.type" :hint="locks.typeAndSide ? 'Has journal lines: stays.' : undefined">
                        <SelectInput id="coa-type" :model-value="form.type" :options="ACCOUNT_TYPES" :disabled="locks.typeAndSide" @update:model-value="chooseType" />
                    </Field>
                    <Field id="coa-side" label="Normal side" :hint="locks.typeAndSide ? 'Has journal lines: stays.' : 'Suggested from the type.'" :error="form.errors.normal_side">
                        <SelectInput id="coa-side" v-model="form.normal_side" :options="sides" :disabled="locks.typeAndSide" />
                    </Field>
                </div>
                <Field id="coa-parent" label="Under" optional hint="The heading this account sits under in reports." :error="form.errors.parent_id">
                    <SelectInput id="coa-parent" v-model="form.parent_id" placeholder="Top level" :options="parents" />
                </Field>
                <Field id="coa-postable" label="Postable" :hint="locks.postable ? 'Has journal lines: stays postable.' : 'Journals post to postable accounts; a heading only groups others.'" :error="form.errors.is_postable">
                    <label class="flex items-center gap-2 text-ui"><input id="coa-postable" v-model="form.is_postable" type="checkbox" class="size-3.5 accent-accent" :disabled="locks.postable" />Journals can post to it</label>
                </Field>
                <template v-if="!editing">
                    <Field id="coa-control" label="Control account" optional hint="Its balance is the total of a subledger (premium due, claims, agents …) and is checked at month end." :error="form.errors.control_subledger">
                        <label class="flex items-center gap-2 text-ui"><input id="coa-control" v-model="form.is_control" type="checkbox" class="size-3.5 accent-accent" />Control account of a subledger</label>
                    </Field>
                    <Field v-if="form.is_control" id="coa-subledger" label="Subledger" :error="form.errors.control_subledger">
                        <SelectInput id="coa-subledger" v-model="form.control_subledger" placeholder="Choose the subledger" :options="subledgerOptions" />
                    </Field>
                    <Field id="coa-currency" label="Currency" optional :hint="`Leave empty to accept any currency. Books are kept in ${entity.currency}.`" :error="form.errors.currency">
                        <div class="w-24"><TextInput id="coa-currency" v-model="form.currency" :maxlength="3" /></div>
                    </Field>
                </template>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
