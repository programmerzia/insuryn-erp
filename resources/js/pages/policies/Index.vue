<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';

interface PolicyRow { id: string; number: string | null; status: string; inception: string; expiry: string; policyholder: string; product_code: string; gross_premium: string }
const props = defineProps<{ filters: { status: string; search: string }; statuses: string[]; policies: { data: PolicyRow[]; current_page: number; last_page: number; total: number } }>();

const filters = reactive({ ...props.filters });
</script>

<template>
    <AppLayout title="Policies">
        <PageHeader eyebrow="Operations" title="Policies" description="Quotes and policies of this entity, newest first.">
            <Link href="/policies/create"><Button>New quote</Button></Link>
        </PageHeader>
        <form class="mb-4 flex flex-wrap gap-2" @submit.prevent="router.get('/policies', filters, { preserveState: true })">
            <Input v-model="filters.search" placeholder="Policy number or policyholder" class="w-72" aria-label="Search policies" />
            <SelectInput v-model="filters.status" placeholder="Any status" :options="statuses.map((s) => ({ value: s, label: s }))" class="w-44" aria-label="Status" />
            <Button type="submit" variant="ghost">Filter</Button>
        </form>
        <Table>
            <TableHeader>
                <TableRow><TableHead>Number</TableHead><TableHead>Policyholder</TableHead><TableHead>Product</TableHead><TableHead>Cover</TableHead><TableHead class="text-right">Gross premium</TableHead><TableHead>Status</TableHead></TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="policy in policies.data" :key="policy.id">
                    <TableCell><Link :href="`/policies/${policy.id}`" class="font-mono text-blueprint hover:underline">{{ policy.number ?? 'Quote' }}</Link></TableCell>
                    <TableCell>{{ policy.policyholder }}</TableCell>
                    <TableCell class="font-mono">{{ policy.product_code }}</TableCell>
                    <TableCell class="text-ivory-dim">{{ policy.inception }} – {{ policy.expiry }}</TableCell>
                    <TableCell class="text-right font-mono tabular-nums">{{ policy.gross_premium }}</TableCell>
                    <TableCell><StatusBadge :status="policy.status" /></TableCell>
                </TableRow>
                <TableEmpty v-if="policies.data.length === 0" :colspan="6">No policies match.</TableEmpty>
            </TableBody>
        </Table>
        <Pagination :page="policies" />
    </AppLayout>
</template>
