<script setup lang="ts">
import { AlertDialogContent, AlertDialogDescription, AlertDialogOverlay, AlertDialogPortal, AlertDialogRoot, AlertDialogTitle } from 'reka-ui';
import { computed } from 'vue';
import { confirmState, settleConfirm } from '@/lib/confirm';

// The buttons settle the answer themselves. Reka's AlertDialogAction/Cancel close the dialog before their click handler runs, and closing settles
// the request as "no" — so confirming resolved false and nothing happened (found by the Phase 3 flow audit on "make proposal"). Esc still answers no.
const open = computed({ get: () => confirmState.request !== null, set: (value) => !value && settleConfirm(false) });
</script>

<template>
    <AlertDialogRoot v-model:open="open">
        <AlertDialogPortal>
            <AlertDialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <AlertDialogContent v-if="confirmState.request" class="fixed top-[24vh] left-1/2 z-50 w-[440px] max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-panel border border-line bg-surface p-4 text-ink shadow-float outline-none">
                <AlertDialogTitle class="text-section font-semibold">{{ confirmState.request.title }}</AlertDialogTitle>
                <AlertDialogDescription class="mt-1 text-ui text-ink-2">{{ confirmState.request.body }}</AlertDialogDescription>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="settleConfirm(false)">{{ confirmState.request.cancelLabel ?? 'Cancel' }}</button>
                    <button
                        type="button"
                        class="h-8 rounded-control px-3 text-ui font-medium"
                        :class="confirmState.request.tone === 'danger' ? 'border border-danger text-danger hover:bg-surface-2' : 'bg-accent text-accent-ink hover:bg-accent-hover'"
                        @click="settleConfirm(true)"
                    >
                        {{ confirmState.request.confirmLabel }}
                    </button>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>
