<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';

/** Toolbar period filter: "From … to …" or "As of …", keyboard dates, applied as query parameters (kept in the URL). */
const props = defineProps<{ url: string; from?: string; to?: string; asOf?: string; extra?: Record<string, string> }>();
const range = reactive({ from: props.from ?? '', to: props.to ?? '', as_of: props.asOf ?? '' });

function apply(): void {
    const query = props.asOf !== undefined ? { as_of: range.as_of } : { from: range.from, to: range.to };
    router.get(props.url, { ...props.extra, ...query }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <form class="ml-2 flex shrink-0 items-center gap-1.5 text-ui whitespace-nowrap text-ink-2" @submit.prevent="apply">
        <template v-if="asOf !== undefined">
            <label for="filter-as-of">As of</label>
            <DateInput id="filter-as-of" v-model="range.as_of" class="w-32" @update:model-value="apply" />
        </template>
        <template v-else>
            <label for="filter-from">From</label>
            <DateInput id="filter-from" v-model="range.from" class="w-32" @update:model-value="apply" />
            <label for="filter-to">to</label>
            <DateInput id="filter-to" v-model="range.to" class="w-32" @update:model-value="apply" />
        </template>
    </form>
</template>
