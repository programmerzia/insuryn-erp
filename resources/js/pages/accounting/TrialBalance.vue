<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Table, TableBody, TableCell, TableEmpty, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { EntityRef, TrialBalanceRow } from '@/types/accounting';

const props = defineProps<{
    entity: EntityRef;
    asOf: string;
    rows: TrialBalanceRow[];
    totals: { debit: string; credit: string; balanced: boolean };
}>();

const asOf = ref(props.asOf);

function reload(): void {
    router.get('/accounting/trial-balance', { as_of: asOf.value, entity_id: props.entity.id }, { preserveState: true });
}
</script>

<template>
    <AppLayout title="Trial balance">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-ui font-medium text-ink-2">{{ entity.code }} · {{ entity.currency }} · LOCAL book</p>
                <h1 class="mt-1 text-title font-semibold">Trial balance</h1>
                <p class="mt-1 text-ui text-ink-2">Posted journal lines up to and including {{ asOf }}.</p>
            </div>
            <form class="flex items-end gap-2" @submit.prevent="reload">
                <label class="grid gap-1 text-dense text-ink-2" for="as-of">
                    As of
                    <input id="as-of" v-model="asOf" type="date" class="rounded-control border border-line-control bg-surface px-2 py-1.5 text-ui text-ink" />
                </label>
                <button type="submit" class="rounded-control bg-accent px-3 py-1.5 text-ui font-medium text-ink hover:bg-accent-hover">Show</button>
            </form>
        </div>

        <Table>
            <TableHeader>
                <TableRow class="hover:bg-transparent">
                    <TableHead class="w-24">Code</TableHead>
                    <TableHead>Account</TableHead>
                    <TableHead class="text-right">Debit</TableHead>
                    <TableHead class="text-right">Credit</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="row in rows" :key="row.accountId">
                    <TableCell class=" text-ink-2">{{ row.code }}</TableCell>
                    <TableCell>{{ row.name }} <span class="ml-1 text-dense text-ink-2">{{ row.type }}</span></TableCell>
                    <TableCell class="text-right tabular-nums">{{ row.debit }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ row.credit }}</TableCell>
                </TableRow>
                <TableEmpty v-if="rows.length === 0" :colspan="4">No posted journals up to {{ asOf }}.</TableEmpty>
            </TableBody>
            <TableFooter>
                <TableRow class="hover:bg-transparent">
                    <TableCell colspan="2">
                        Totals
                        <span v-if="totals.balanced" class="ml-2 text-dense text-ok">balanced</span>
                        <span v-else class="ml-2 text-dense text-danger">out of balance</span>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">{{ totals.debit }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{ totals.credit }}</TableCell>
                </TableRow>
            </TableFooter>
        </Table>
    </AppLayout>
</template>
