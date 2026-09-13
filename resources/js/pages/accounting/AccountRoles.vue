<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/**
 * Fix F4 (design §3.4): the posting rules post to account roles; this screen says which account each role goes to in an entity and book, from when,
 * and warns about roles the rules use that have no account (their events would fail). Remapping starts from a date and never rewrites history.
 */
interface Mapping { account_code: string; account_name: string; effective_from: string; effective_to: string | null }
interface RoleRow {
    code: string; description: string; used_by_rules: string[]; account_id: string | null; account_code: string | null; account_name: string | null;
    effective_from: string | null; effective_to: string | null; control_subledger: string | null; history: Mapping[];
}
interface Option { id: string; code: string; name: string }
const props = defineProps<{
    entities: Option[]; books: Option[]; entityId: string; bookId: string; today: string; roles: RoleRow[];
    unmapped: { code: string; description: string; used_by_rules: string[] }[];
    accounts: { id: string; code: string; name: string; is_control: boolean }[];
}>();

const active = ref<string | null>(null);
const onlyUnmapped = ref(false);
const remapping = ref<RoleRow | null>(null);
const drawerOpen = ref(false);
const unmappedCodes = computed(() => new Set(props.unmapped.map((u) => u.code)));
const rows = computed(() => (onlyUnmapped.value ? props.roles.filter((r) => unmappedCodes.value.has(r.code)) : props.roles));
const form = useForm({ entity_id: props.entityId, book_id: props.bookId, role: '', account_id: '', effective_from: props.today });
const accountOptions = computed(() => props.accounts
    .filter((a) => (remapping.value?.control_subledger ? a.is_control : !a.is_control))
    .map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` })));

function remap(row: RoleRow): void {
    remapping.value = row;
    form.defaults({ entity_id: props.entityId, book_id: props.bookId, role: row.code, account_id: row.account_id ?? '', effective_from: props.today });
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function choose(entity: string, book: string): void {
    router.get('/accounting/account-roles', { entity, book }, { preserveState: false });
}
const statusOf = (r: RoleRow) => (r.account_id ? 'mapped' : unmappedCodes.value.has(r.code) ? 'no_account' : 'not_used');
const columns: DataColumn<RoleRow>[] = [
    { id: 'description', header: 'Account role', value: (r) => r.description, width: 280 },
    { id: 'code', header: 'Code', value: (r) => r.code, width: 200, muted: true },
    { id: 'account', header: 'Account', value: (r) => (r.account_code ? `${r.account_code} · ${r.account_name}` : 'No account'), width: 260 },
    { id: 'from', header: 'From', type: 'date', value: (r) => r.effective_from, width: 120 },
    { id: 'to', header: 'Until', type: 'date', value: (r) => r.effective_to, width: 120 },
    { id: 'status', header: 'Status', type: 'status', value: statusOf, filterOptions: ['mapped', 'no_account', 'not_used'] },
];
</script>

<template>
    <AppLayout title="Account roles" fill>
        <div v-if="unmapped.length" class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-l-2 border-warn bg-surface-2 px-3 py-2 text-ui" role="status">
            <p class="min-w-0 flex-1">
                {{ unmapped.length }} account {{ unmapped.length === 1 ? 'role' : 'roles' }} used by the posting rules {{ unmapped.length === 1 ? 'has' : 'have' }} no account:
                {{ unmapped.map((u) => u.description).join(', ') }}. Their accounting events fail until they are mapped.
            </p>
            <Button variant="secondary" size="sm" @click="onlyUnmapped = !onlyUnmapped">{{ onlyUnmapped ? 'Show all roles' : 'Show only these' }}</Button>
        </div>
        <QueueView
            id="accounting-account-roles"
            v-model:active="active"
            title="Account roles"
            :columns="columns"
            :rows="rows"
            :row-key="(r) => r.code"
            empty-text="No account roles to show."
            :empty-action="{ label: 'Import a chart of accounts', href: '/accounting/imports' }"
            :inspector-title="(r) => r.description"
            :inspector-subtitle="(r) => r.code"
        >
            <template #toolbar>
                <label class="ml-2 flex items-center gap-1.5 text-ui text-ink-2">Entity
                    <SelectInput id="entity" :model-value="entityId" class="w-44" :options="entities.map((e) => ({ value: e.id, label: `${e.code} · ${e.name}` }))" @update:model-value="(v) => choose(v ?? entityId, bookId)" />
                </label>
                <label class="flex items-center gap-1.5 text-ui text-ink-2">Book
                    <SelectInput id="book" :model-value="bookId" class="w-36" :options="books.map((b) => ({ value: b.id, label: b.code }))" @update:model-value="(v) => choose(entityId, v ?? bookId)" />
                </label>
            </template>
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Account', value: row.account_code ? `${row.account_code} · ${row.account_name}` : 'No account' },
                        { label: 'From', value: row.effective_from ? formatDate(row.effective_from) : null },
                        { label: 'Until', value: row.effective_to ? `${formatDate(row.effective_to)} (not included)` : row.account_id ? 'No end date' : null },
                        { label: 'Control of', value: row.control_subledger ? `${row.control_subledger} subledger` : null },
                        { label: 'Used by', value: row.used_by_rules.length ? row.used_by_rules.join(', ') : 'No posting rule in force' },
                    ]"
                />
                <Button class="mt-4" variant="secondary" @click="remap(row)">{{ row.account_id ? 'Map to another account' : 'Map to an account' }}</Button>
                <h3 class="mt-6 mb-1 text-ui font-medium">History</h3>
                <ul v-if="row.history.length" class="grid gap-1 text-dense">
                    <li v-for="(m, i) in row.history" :key="i">{{ m.account_code }} · {{ m.account_name }} <span class="text-ink-2">— from {{ formatDate(m.effective_from) }}{{ m.effective_to ? ` until ${formatDate(m.effective_to)}` : '' }}</span></li>
                </ul>
                <p v-else class="text-dense text-ink-2">Never mapped.</p>
            </template>
        </QueueView>

        <Drawer v-model:open="drawerOpen" :title="remapping ? `Map: ${remapping.description}` : 'Map account role'">
            <p class="mb-4 text-ui text-ink-2">From this date the posting rules post this role to the account you choose. The current account keeps everything before it.</p>
            <FormLayout submit-label="Map role" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/accounting/account-roles', { preserveScroll: true, onSuccess: () => (drawerOpen = false) })" @cancel="drawerOpen = false">
                <Field id="account_id" label="Account" :hint="remapping?.control_subledger ? `Control accounts only: the ${remapping.control_subledger} subledger reconciles to this role.` : undefined" :error="form.errors.account_id">
                    <SelectInput id="account_id" v-model="form.account_id" placeholder="Choose an account" :options="accountOptions" />
                </Field>
                <Field id="effective_from" label="From" :error="form.errors.effective_from"><DateInput id="effective_from" v-model="form.effective_from" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
