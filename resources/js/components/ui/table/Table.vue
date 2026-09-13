<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <div class="relative w-full overflow-x-auto border border-line bg-surface">
        <table :class="cn('w-full caption-bottom text-dense', props.class)">
            <slot />
        </table>
    </div>
</template>
