<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ filters: { status: string }; statuses: string[]; claims: { data: { id: string; number: string; status: string; loss_date: string; reported_on: string; reserve: string; policy_number: string | null; policyholder: string }[]; current_page: number; last_page: number; total: number } }>();
const filters = reactive({ ...props.filters });
</script>

<template>
    <AppLayout title="Claims">
        <PageHeader eyebrow="Claims" title="Claims" description="Registered claims, most recently reported first.">
            <Link href="/claims/create"><Button>Register claim</Button></Link>
        </PageHeader>
        <form class="mb-4 flex gap-2" @submit.prevent="router.get('/claims', filters, { preserveState: true })">
            <SelectInput v-model="filters.status" placeholder="Any status" :options="statuses.map((s) => ({ value: s, label: s }))" class="w-44" aria-label="Status" />
            <Button type="submit" variant="ghost">Filter</Button>
        </form>
        <Table>
            <TableHeader><TableRow><TableHead>Claim</TableHead><TableHead>Policy</TableHead><TableHead>Policyholder</TableHead><TableHead>Loss</TableHead><TableHead class="text-right">Reserve</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
            <TableBody>
                <TableRow v-for="claim in claims.data" :key="claim.id">
                    <TableCell><Link :href="`/claims/${claim.id}`" class="font-mono text-blueprint hover:underline">{{ claim.number }}</Link></TableCell>
                    <TableCell class="font-mono">{{ claim.policy_number }}</TableCell><TableCell>{{ claim.policyholder }}</TableCell><TableCell>{{ claim.loss_date }}</TableCell>
                    <TableCell class="text-right font-mono tabular-nums">{{ claim.reserve }}</TableCell><TableCell><StatusBadge :status="claim.status" /></TableCell>
                </TableRow>
                <TableEmpty v-if="claims.data.length === 0" :colspan="6">No claims match.</TableEmpty>
            </TableBody>
        </Table>
        <Pagination :page="claims" />
    </AppLayout>
</template>
