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

/** Phase 2.0 user administration: everyone in the organisation, their roles, and inviting someone new. */
interface UserRow { id: string; name: string; email: string; status: string; roles: string[] }
const props = defineProps<{ users: UserRow[]; roles?: { id: string; name: string }[] }>();

const active = ref<string | null>(null);
const inviting = ref(false);
const form = useForm({ name: '', email: '', role_id: '' });
const columns: DataColumn<UserRow>[] = [
    { id: 'name', header: 'Name', value: (u) => u.name, href: (u) => `/admin/users/${u.id}`, width: 220 },
    { id: 'email', header: 'Email', value: (u) => u.email, width: 260, muted: true },
    { id: 'roles', header: 'Roles', value: (u) => (u.roles.length ? u.roles.join(', ') : 'No roles'), width: 320 },
    { id: 'status', header: 'Status', type: 'status', value: (u) => u.status, filterOptions: ['active', 'inactive'] },
];
</script>

<template>
    <AppLayout title="Users" fill>
        <QueueView
            id="admin-users"
            v-model:active="active"
            title="Users"
            :columns="columns"
            :rows="users"
            :row-key="(u) => u.id"
            empty-text="No users yet."
            :action="{ label: 'Invite user' }"
            :inspector-title="(u) => u.name"
            :inspector-subtitle="(u) => u.email"
            @action="inviting = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Roles', value: row.roles.join(', ') || 'No roles' }, { label: 'Status', value: row.status === 'active' ? 'Can sign in' : 'Cannot sign in' }]" />
                <Link :href="`/admin/users/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the user</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="inviting" title="Invite user">
            <p class="mb-4 text-ui text-ink-2">They get an email with a link to set their password. Choose a first role now, or give roles on their page later (for one branch, for example).</p>
            <FormLayout submit-label="Send invitation" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/admin/users')" @cancel="inviting = false">
                <Field id="name" label="Name" :error="form.errors.name"><TextInput v-model="form.name" autocomplete="off" /></Field>
                <Field id="email" label="Email" :error="form.errors.email"><TextInput v-model="form.email" type="email" autocomplete="off" /></Field>
                <!-- GA-22: a first role for the whole organisation, as the setup wizard offers. -->
                <Field id="role_id" label="Role" optional hint="For the whole organisation." :error="form.errors.role_id">
                    <SelectInput id="role_id" v-model="form.role_id" placeholder="No role yet" :options="(props.roles ?? []).map((r) => ({ value: r.id, label: r.name }))" />
                </Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
