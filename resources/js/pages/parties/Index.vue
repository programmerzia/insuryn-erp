<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';

interface PartyRow { id: string; kind: string; display_name: string; tax_id: string | null; roles: string[] }
const props = defineProps<{ search: string; parties: { data: PartyRow[]; current_page: number; last_page: number; total: number }; kinds: string[]; roles: string[] }>();

const { can } = usePermissions();
const active = ref<string | null>(null);
const creating = ref(false);
const words = (value: string) => value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const form = useForm({ kind: 'individual', display_name: '', tax_id: '', roles: ['customer'] as string[] });
const columns: DataColumn<PartyRow>[] = [
    { id: 'name', header: 'Name', value: (p) => p.display_name, href: (p) => `/parties/${p.id}`, width: 260 },
    { id: 'kind', header: 'Kind', value: (p) => words(p.kind), width: 120, filterOptions: undefined },
    { id: 'roles', header: 'Roles', value: (p) => p.roles.map(words).join(', '), width: 260, muted: true },
    { id: 'tax_id', header: 'Tax ID', value: (p) => p.tax_id, width: 140, muted: true },
];
</script>

<template>
    <AppLayout title="Parties" fill>
        <QueueView
            id="parties"
            v-model:active="active"
            title="Parties"
            :columns="columns"
            :rows="parties.data"
            :row-key="(p) => p.id"
            empty-text="No parties yet: add the first customer."
            :action="can('party.manage') ? { label: 'New party' } : null"
            :inspector-title="(p) => p.display_name"
            :inspector-subtitle="(p) => words(p.kind)"
            @action="creating = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Roles', value: row.roles.map(words).join(', ') }, { label: 'Tax ID', value: row.tax_id }]" />
                <Link :href="`/parties/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the party</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="creating" title="New party">
            <FormLayout submit-label="Create party" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/parties')" @cancel="creating = false">
                <Field id="display_name" label="Name" :error="form.errors.display_name"><TextInput v-model="form.display_name" /></Field>
                <Field id="kind" label="Kind" :error="form.errors.kind"><SelectInput id="kind" v-model="form.kind" :options="kinds.map((k) => ({ value: k, label: words(k) }))" /></Field>
                <Field id="tax_id" label="Tax ID" optional :error="form.errors.tax_id"><TextInput v-model="form.tax_id" /></Field>
                <fieldset class="grid gap-1.5">
                    <legend class="mb-1 text-ui font-medium">Roles</legend>
                    <label v-for="role in roles" :key="role" class="flex items-center gap-2 text-ui"><input v-model="form.roles" type="checkbox" :value="role" class="size-3.5 accent-accent" />{{ words(role) }}</label>
                    <p v-if="form.errors.roles" class="text-dense text-danger" role="alert">{{ form.errors.roles }}</p>
                </fieldset>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
