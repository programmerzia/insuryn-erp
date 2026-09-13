<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';

/** A side drawer for short inline tasks (create a customer without leaving the form). Esc closes. */
defineProps<{ title: string }>();
const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <DialogContent class="fixed inset-y-0 right-0 z-50 flex w-[440px] max-w-full flex-col border-l border-line bg-surface text-ink shadow-float outline-none" :aria-describedby="undefined">
                <div class="flex h-12 items-center justify-between border-b border-line px-4">
                    <DialogTitle class="text-section font-semibold">{{ title }}</DialogTitle>
                    <DialogClose class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" aria-label="Close"><X :size="16" :stroke-width="1.5" /></DialogClose>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-4"><slot /></div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
