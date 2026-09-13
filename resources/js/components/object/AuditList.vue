<script setup lang="ts">
import type { AuditRow } from '@/components/object/types';

/** The raw audit trail: who did what, when, why, and each field before and after. */
defineProps<{ rows: AuditRow[] }>();
</script>

<template>
    <div v-if="rows.length" class="overflow-x-auto border border-line">
        <table class="w-full table-fixed border-separate border-spacing-0 text-dense">
            <colgroup><col style="width: 170px" /><col style="width: 190px" /><col style="width: 150px" /><col /></colgroup>
            <thead class="bg-surface-2 text-ink-2">
                <tr class="h-(--row-h)">
                    <th class="border-b border-line px-3 text-left font-medium">When</th>
                    <th class="border-b border-line px-3 text-left font-medium">What</th>
                    <th class="border-b border-line px-3 text-left font-medium">By</th>
                    <th class="border-b border-line px-3 text-left font-medium">Changes and reason</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index" class="align-top">
                    <td class="border-b border-line px-3 py-2 text-ink-2 tabular-nums">{{ row.at.slice(0, 16).replace('T', ' ') }}</td>
                    <td class="border-b border-line px-3 py-2">{{ row.action }}</td>
                    <td class="border-b border-line px-3 py-2">{{ row.by }}</td>
                    <td class="border-b border-line px-3 py-2">
                        <p v-if="row.reason" class="mb-1">“{{ row.reason }}”</p>
                        <p v-for="change in row.changes" :key="change.field" class="truncate text-ink-2" :title="`${change.field}: ${change.before ?? '—'} → ${change.after ?? '—'}`">
                            {{ change.field }}: <span v-if="change.before !== null">{{ change.before }} → </span><span class="text-ink">{{ change.after ?? '—' }}</span>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <p v-else class="text-ui text-ink-2">No audit entries.</p>
</template>
