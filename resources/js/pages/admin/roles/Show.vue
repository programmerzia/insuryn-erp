<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { type SodRule, sodConflicts } from '@/lib/sod';
import { useUnsavedGuard } from '@/lib/unsaved';

/** One role: its permissions grouped by area (saved together, checked against every holder) and who holds it. */
const props = defineProps<{
    role: { id: string; code: string; name: string };
    granted: string[];
    catalogue: { label: string; permissions: { code: string; label: string; help?: string | null }[] }[];
    holders: { id: string; name: string; email: string; status: string }[];
    /** GA-22: user-level segregation rules (`accounting.*` covers every accounting permission). */
    sodRules?: SodRule[];
}>();

const form = useForm({ permissions: [...props.granted] });
const labels = computed(() => new Map(props.catalogue.flatMap((g) => g.permissions.map((p) => [p.code, p.label] as const))));
const conflicts = computed(() => sodConflicts(form.permissions, props.sodRules ?? []));
useUnsavedGuard(() => form.isDirty);
const changes = computed(() => {
    const now = new Set(form.permissions);
    const before = new Set(props.granted);
    return { added: form.permissions.filter((p) => !before.has(p)).length, removed: props.granted.filter((p) => !now.has(p)).length };
});

function save(): void {
    form.put(`/admin/roles/${props.role.id}`, { preserveScroll: true, onSuccess: () => form.defaults({ permissions: [...form.permissions] }) });
}
async function remove(): Promise<void> {
    const ok = await confirmAction({ title: `Delete ${props.role.name}?`, body: 'The role and its permission list are removed. Nobody holds it, so no one loses access.', confirmLabel: 'Delete role', tone: 'danger' });
    if (ok) router.delete(`/admin/roles/${props.role.id}`);
}
</script>

<template>
    <AppLayout :title="role.name">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <Link href="/admin/roles" class="text-dense text-accent-text hover:underline">Roles</Link>
                <h1 class="text-title font-semibold">{{ role.name }}</h1>
                <p class="text-ui text-ink-2">{{ granted.length }} permissions · held by {{ holders.length }} {{ holders.length === 1 ? 'user' : 'users' }}</p>
            </div>
            <Button v-if="holders.length === 0" variant="danger" @click="remove">Delete role</Button>
        </div>
        <FormBanner />

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_320px]">
            <form aria-labelledby="permissions-heading" @submit.prevent="save">
                <h2 id="permissions-heading" class="mb-3 text-section font-semibold">Permissions</h2>
                <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    <fieldset v-for="group in catalogue" :key="group.label" class="grid content-start gap-1.5">
                        <legend class="mb-1 text-ui font-medium">{{ group.label }}</legend>
                        <label v-for="permission in group.permissions" :key="permission.code" class="grid grid-cols-[14px_1fr] items-start gap-x-2 text-ui" :title="permission.code">
                            <input v-model="form.permissions" type="checkbox" :value="permission.code" class="mt-[3px] size-3.5 accent-accent" :aria-describedby="permission.help ? `help-${permission.code}` : undefined" />
                            <span>{{ permission.label }}</span>
                            <span v-if="permission.help" :id="`help-${permission.code}`" class="col-start-2 text-dense text-ink-2">{{ permission.help }}</span>
                        </label>
                    </fieldset>
                </div>
                <div v-if="conflicts.length" class="mt-4 rounded-control border border-warn bg-surface-2 px-3 py-2 text-ui" role="status" data-sod-notice>
                    <p class="font-medium">Segregation of duties</p>
                    <p v-for="c in conflicts" :key="`${c.a}-${c.b}`" class="text-ink-2">
                        {{ labels.get(c.a) ?? c.a }} and {{ labels.get(c.b) ?? c.b }} {{ c.mode === 'block' ? 'are kept apart: nobody may hold both, so anyone holding this role blocks the save.' : 'are usually kept apart.' }}
                    </p>
                </div>
                <p v-if="Object.keys(form.errors).some((k) => k.startsWith('permissions'))" class="mt-3 text-dense text-danger" role="alert">Some permissions are not in the catalogue. Refresh the page.</p>
                <div class="sticky -bottom-4 mt-6 flex items-center gap-3 border-t border-line bg-surface pt-3 pb-7">
                    <Button type="submit" :disabled="form.processing || !form.isDirty">Save permissions</Button>
                    <Button v-if="form.isDirty" variant="ghost" @click="form.reset()">Discard changes</Button>
                    <p v-if="form.isDirty" class="text-dense text-ink-2">
                        {{ [changes.added ? `${changes.added} to add` : '', changes.removed ? `${changes.removed} to remove` : ''].filter(Boolean).join(', ') }}. Checked against everyone who holds this role.
                    </p>
                </div>
            </form>

            <section aria-labelledby="holders-heading">
                <h2 id="holders-heading" class="mb-3 text-section font-semibold">Held by</h2>
                <ul v-if="holders.length" class="grid divide-y divide-line border-y border-line">
                    <li v-for="holder in holders" :key="holder.id" class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <Link :href="`/admin/users/${holder.id}`" class="block truncate text-ui text-accent-text hover:underline">{{ holder.name }}</Link>
                            <p class="truncate text-dense text-ink-2">{{ holder.email }}</p>
                        </div>
                        <StatusBadge v-if="holder.status !== 'active'" :status="holder.status" />
                    </li>
                </ul>
                <p v-else class="text-ui text-ink-2">Nobody holds this role. Give it to someone from their user page.</p>
            </section>
        </div>
    </AppLayout>
</template>
