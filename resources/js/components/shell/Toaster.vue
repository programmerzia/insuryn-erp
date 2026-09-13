<script setup lang="ts">
import { dismissToast, toasts } from '@/lib/toasts';
</script>

<template>
    <div class="pointer-events-none fixed bottom-10 left-3 z-50 flex w-96 max-w-[calc(100vw-1.5rem)] flex-col gap-2" aria-live="polite" role="region" aria-label="Notifications">
        <div v-for="item in toasts" :key="item.id" class="pointer-events-auto flex items-center gap-3 rounded-panel border border-line bg-surface px-3 py-2 text-ui text-ink shadow-float" :role="item.tone === 'danger' ? 'alert' : 'status'">
            <span class="size-1.5 shrink-0 rounded-full" :class="{ 'bg-ok': item.tone === 'ok', 'bg-danger': item.tone === 'danger', 'bg-ink-2': item.tone === 'neutral' }" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ item.message }}</span>
            <button v-if="item.undo" type="button" class="font-medium text-accent-text hover:underline" @click="item.undo?.(); dismissToast(item.id)">Undo</button>
        </div>
    </div>
</template>
