<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ status: string }>();

/** Journal status → chip tone. Blueprint = settled, amber = in progress, brick = stopped (CoreBari status colours). */
const tone = computed(() => {
    switch (props.status) {
        case 'posted':
            return 'border-green/40 bg-green/10 text-green';
        case 'reversed':
        case 'cancelled':
        case 'failed':
            return 'border-brick-soft/50 bg-brick/15 text-brick-soft';
        case 'draft':
        case 'pending_approval':
        case 'approved':
        case 'queued':
        case 'posting':
            return 'border-amber/40 bg-amber/10 text-amber';
        default:
            return 'border-line-control text-ivory-dim';
    }
});
</script>

<template>
    <span :class="cn('inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium whitespace-nowrap', tone)">
        {{ status.replace('_', ' ') }}
    </span>
</template>
