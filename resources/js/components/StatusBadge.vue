<script setup lang="ts">
import { computed } from 'vue';
import { statusTone, statusWord } from '@/lib/status';

/** Brief §4: status as a small dot and a word, never a coloured pill. The word carries the meaning; the dot only helps scanning. */
const props = defineProps<{ status: string }>();
const tone = computed(() => statusTone(props.status));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
        <span class="size-1.5 shrink-0 rounded-full" :class="{ 'bg-ok': tone === 'ok', 'bg-warn': tone === 'warn', 'bg-danger': tone === 'danger', 'bg-ink-2': tone === 'neutral' }" aria-hidden="true" />
        {{ statusWord(status) }}
    </span>
</template>
