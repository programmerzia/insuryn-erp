<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

interface PartyRow { id: string; kind: string; display_name: string; tax_id: string | null; roles: string[] }
const props = defineProps<{ search: string; parties: { data: PartyRow[]; current_page: number; last_page: number; total: number }; kinds: string[]; roles: string[] }>();

const search = ref(props.search);
const form = useForm({ kind: 'individual', display_name: '', tax_id: '', roles: ['customer'] as string[] });
</script>

<template>
    <AppLayout title="Parties">
        <PageHeader eyebrow="Operations" title="Parties" description="Customers, policyholders, agents and other people or organisations you deal with.">
            <form class="flex gap-2" @submit.prevent="router.get('/parties', { search }, { preserveState: true })">
                <Input v-model="search" placeholder="Name or tax ID" class="w-56" aria-label="Search parties" />
                <Button type="submit" variant="ghost">Search</Button>
            </form>
        </PageHeader>
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Kind</TableHead><TableHead>Roles</TableHead><TableHead>Tax ID</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="party in parties.data" :key="party.id">
                            <TableCell><Link :href="`/parties/${party.id}`" class="text-accent-text hover:underline">{{ party.display_name }}</Link></TableCell>
                            <TableCell>{{ party.kind }}</TableCell>
                            <TableCell class="text-ink-2">{{ party.roles.join(', ') }}</TableCell>
                            <TableCell class=" text-dense">{{ party.tax_id }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="parties.data.length === 0" :colspan="4">No parties found.</TableEmpty>
                    </TableBody>
                </Table>
                <Pagination :page="parties" />
            </div>
            <Card>
                <h2 class="text-section font-semibold">New party</h2>
                <FormBanner />
                <form class="mt-4 grid gap-4" @submit.prevent="form.post('/parties')">
                    <Field id="kind" label="Kind" :error="form.errors.kind"><SelectInput id="kind" v-model="form.kind" :options="kinds.map((k) => ({ value: k, label: k }))" /></Field>
                    <Field id="display_name" label="Name" :error="form.errors.display_name"><Input id="display_name" v-model="form.display_name" /></Field>
                    <Field id="tax_id" label="Tax ID" :error="form.errors.tax_id"><Input id="tax_id" v-model="form.tax_id" /></Field>
                    <fieldset class="grid gap-1.5">
                        <legend class="text-ui font-medium">Roles</legend>
                        <label v-for="role in roles" :key="role" class="flex items-center gap-2 text-ui text-ink-2">
                            <input v-model="form.roles" type="checkbox" :value="role" class="size-4 accent-brick" /> {{ role }}
                        </label>
                        <p v-if="form.errors.roles" class="text-ui text-danger">{{ form.errors.roles }}</p>
                    </fieldset>
                    <Button type="submit" :disabled="form.processing">Create party</Button>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
