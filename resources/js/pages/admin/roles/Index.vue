<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/** Phase 2.0 role administration: the tenant's roles (seeded templates and its own), how much each grants and to how many people. */
interface RoleRow { id: string; code: string; name: string; permissions: number; holders: number }
defineProps<{ roles: RoleRow[] }>();

const active = ref<string | null>(null);
const creating = ref(false);
const form = useForm({ name: '' });
const columns: DataColumn<RoleRow>[] = [
    { id: 'name', header: 'Role', value: (r) => r.name, href: (r) => `/admin/roles/${r.id}`, width: 260 },
    { id: 'permissions', header: 'Permissions', type: 'number', value: (r) => r.permissions, width: 120 },
    { id: 'holders', header: 'Users', type: 'number', value: (r) => r.holders, width: 100 },
];
</script>

<template>
    <AppLayout title="Roles" fill>
        <QueueView
            id="admin-roles"
            v-model:active="active"
            title="Roles"
            :columns="columns"
            :rows="roles"
            :row-key="(r) => r.id"
            empty-text="No roles yet. Create the first one."
            :action="{ label: 'New role' }"
            :inspector-title="(r) => r.name"
            @action="creating = true"
        >
            <template #details="{ row }">
                <DetailList :items="[{ label: 'Permissions', value: row.permissions, num: true }, { label: 'Users', value: row.holders, num: true }]" />
                <Link :href="`/admin/roles/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">Open the role</Link>
            </template>
        </QueueView>
        <Drawer v-model:open="creating" title="New role">
            <FormLayout submit-label="Create role" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/admin/roles')" @cancel="creating = false">
                <Field id="name" label="Name" hint="For example: Collections supervisor" :error="form.errors.name"><TextInput v-model="form.name" autocomplete="off" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
