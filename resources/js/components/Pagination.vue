<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps<{ page: { current_page: number; last_page: number; total: number } }>();
const current = usePage();

function go(to: number): void {
    const url = new URL(current.url, window.location.origin);
    url.searchParams.set('page', String(to));
    router.get(url.pathname + url.search, {}, { preserveState: true });
}
</script>

<template>
    <div v-if="props.page.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ivory-dim">
        <span>{{ props.page.total }} in total</span>
        <div class="flex gap-2">
            <button type="button" class="rounded px-2 py-1 hover:bg-surface-raised disabled:opacity-40" :disabled="props.page.current_page <= 1" @click="go(props.page.current_page - 1)">Previous</button>
            <span>Page {{ props.page.current_page }} of {{ props.page.last_page }}</span>
            <button type="button" class="rounded px-2 py-1 hover:bg-surface-raised disabled:opacity-40" :disabled="props.page.current_page >= props.page.last_page" @click="go(props.page.current_page + 1)">Next</button>
        </div>
    </div>
</template>
