<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';
import StatusBadge from '@/components/StatusBadge.vue';
import { pendingHeadline, type PendingDocument } from '@/lib/closePending';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';

/**
 * Slice 2.1b (D-55, CQ-C4): documents dated in the period that still wait for approval, release or posting. A soft lock goes ahead with them
 * listed as a warning; the lock waits until each is approved or rejected, or — a manual journal pending approval — moved to the next open period
 * by its approver.
 */
const props = defineProps<{ documents: PendingDocument[]; month: string; compact?: boolean }>();

async function move(doc: PendingDocument): Promise<void> {
    const ok = await confirmAction({
        title: 'Move this journal to the next period?',
        body: `${doc.label} is re-dated to the first day of the next open period and keeps waiting for approval there, so ${props.month} can be locked. The move is kept in the audit trail.`,
        confirmLabel: 'Move to next period',
    });
    if (ok) router.post(`/close/journals/${doc.id}/move-to-next-period`, {}, { preserveScroll: true });
}
</script>

<template>
    <section v-if="documents.length" class="rounded-panel border border-line" :aria-label="`Pending documents in ${month}`">
        <header class="flex items-start gap-2 border-b border-line px-4 py-2">
            <TriangleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
            <div class="min-w-0">
                <p class="text-ui font-medium">{{ pendingHeadline(documents.length, month) }}</p>
                <p class="text-dense text-ink-2">Soft-locking can go ahead. Locking waits until each one is approved or rejected, or a pending manual journal is moved to the next period.</p>
            </div>
        </header>
        <ul>
            <li
                v-for="doc in documents"
                :key="`${doc.type}-${doc.id}`"
                class="grid items-center gap-x-3 gap-y-1 border-b border-line px-4 py-2 text-ui last:border-b-0"
                :class="compact ? 'grid-cols-[minmax(0,1fr)_auto]' : 'grid-cols-[96px_minmax(0,1fr)_120px_auto]'"
            >
                <span v-if="!compact" class="tabular-nums text-ink-2">{{ formatDate(doc.date) }}</span>
                <div class="min-w-0">
                    <p class="truncate font-medium" :title="doc.label">{{ doc.label }}</p>
                    <p class="flex flex-wrap items-center gap-x-1 text-dense text-ink-2">
                        <template v-if="compact"><span class="tabular-nums">{{ formatDate(doc.date) }}</span> ·</template>
                        <StatusBadge :status="doc.status" />
                        <template v-if="compact && doc.amount">· <span class="tabular-nums">{{ doc.amount }}</span></template>
                        · <span>{{ doc.cleared_by }}</span>
                    </p>
                </div>
                <span v-if="!compact" class="text-right tabular-nums">{{ doc.amount ?? '' }}</span>
                <div class="flex items-center justify-end gap-2">
                    <Link v-if="doc.link" :href="doc.link" class="text-accent-text hover:underline">Open</Link>
                    <button v-if="doc.can_move" type="button" class="h-7 rounded-control border border-line-control px-2 text-ui hover:bg-surface-2" @click="move(doc)">Move to next period</button>
                </div>
            </li>
        </ul>
    </section>
</template>
