<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import Timeline from '@/components/object/Timeline.vue';
import type { TimelineEntry } from '@/components/object/types';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';

/** One user: what they can do and where (roles by scope), their access, and what administrators changed. */
interface Assignment { role_id: string; role: string; scope_type: string; scope_id: string; scope: string }
interface Place { id: string; code: string; name: string }
const props = defineProps<{
    user: { id: string; name: string; email: string; status: string; self: boolean; invitation_pending?: boolean };
    assignments: Assignment[];
    roles: { id: string; name: string }[];
    entities: Place[];
    branches: Place[];
    timeline: TimelineEntry[];
}>();

const base = `/admin/users/${props.user.id}`;
const form = useForm({ role_id: '', scope_type: 'tenant', scope_id: '' });
const places = computed(() => (form.scope_type === 'entity' ? props.entities : form.scope_type === 'branch' ? props.branches : []));
watch(() => form.scope_type, () => (form.scope_id = places.value.length === 1 ? (places.value[0]?.id ?? '') : ''));
const scopeOptions = [
    { value: 'tenant', label: 'Whole organisation' },
    { value: 'entity', label: 'One legal entity' },
    { value: 'branch', label: 'One branch' },
];

function addRole(): void {
    form.post(`${base}/roles`, { preserveScroll: true, onSuccess: () => form.reset() });
}
function removeRole(assignment: Assignment): void {
    router.post(`${base}/roles/remove`, { role_id: assignment.role_id, scope_type: assignment.scope_type, scope_id: assignment.scope_id }, { preserveScroll: true });
}
async function deactivate(): Promise<void> {
    const ok = await confirmAction({ title: `Deactivate ${props.user.name}?`, body: 'They are signed out everywhere and cannot sign in until reactivated. Their roles and history stay.', confirmLabel: 'Deactivate', tone: 'danger' });
    if (ok) router.post(`${base}/deactivate`, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="user.name">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <Link href="/admin/users" class="text-dense text-accent-text hover:underline">Users</Link>
                <div class="flex items-center gap-3">
                    <h1 class="text-title font-semibold">{{ user.name }}</h1>
                    <StatusBadge :status="user.invitation_pending ? 'invited' : user.status" />
                </div>
                <p class="text-ui text-ink-2">{{ user.email }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <!-- GA-22: only while the invitation is still open. -->
                <Button v-if="user.status === 'active' && user.invitation_pending" variant="secondary" @click="router.post(`${base}/invitation`, {}, { preserveScroll: true })">Resend invitation</Button>
                <Button v-if="user.status === 'active' && !user.self" variant="danger" @click="deactivate">Deactivate</Button>
                <Button v-if="user.status !== 'active'" variant="secondary" @click="router.post(`${base}/reactivate`, {}, { preserveScroll: true })">Reactivate</Button>
            </div>
        </div>
        <FormBanner />

        <section class="max-w-[900px]" aria-labelledby="roles-heading">
            <h2 id="roles-heading" class="mb-2 text-section font-semibold">Roles</h2>
            <Table v-if="assignments.length">
                <TableHeader>
                    <TableRow>
                        <TableHead>Role</TableHead>
                        <TableHead>Applies to</TableHead>
                        <TableHead class="w-24"><span class="sr-only">Actions</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="assignment in assignments" :key="`${assignment.role_id}-${assignment.scope_id}`">
                        <TableCell>{{ assignment.role }}</TableCell>
                        <TableCell class="text-ink-2">{{ assignment.scope }}</TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="sm" :aria-label="`Remove ${assignment.role} (${assignment.scope})`" @click="removeRole(assignment)">Remove</Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <p v-else class="text-ui text-ink-2">No roles yet, so {{ user.name }} can sign in but not open any work area.</p>

            <form class="mt-4 grid items-end gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]" @submit.prevent="addRole">
                <Field id="role_id" label="Role" :error="form.errors.role_id">
                    <SelectInput id="role_id" v-model="form.role_id" placeholder="Choose a role" :options="roles.map((r) => ({ value: r.id, label: r.name }))" />
                </Field>
                <Field id="scope_type" label="Applies to" :error="form.errors.scope_type">
                    <SelectInput id="scope_type" v-model="form.scope_type" :options="scopeOptions" />
                </Field>
                <Field v-if="form.scope_type !== 'tenant'" id="scope_id" :label="form.scope_type === 'entity' ? 'Entity' : 'Branch'" :error="form.errors.scope_id">
                    <SelectInput id="scope_id" v-model="form.scope_id" placeholder="Choose" :options="places.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))" />
                </Field>
                <div v-else />
                <Button type="submit" variant="secondary" :disabled="form.processing || form.role_id === ''">Add role</Button>
            </form>
        </section>

        <section class="mt-8" aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" class="mb-2 text-section font-semibold">Timeline</h2>
            <Timeline :entries="timeline" :empty-text="assignments.length ? 'No changes recorded yet. Roles given when the organisation was set up show above, not here.' : undefined" />
        </section>
    </AppLayout>
</template>
