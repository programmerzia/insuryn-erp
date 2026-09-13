<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { Button } from '@/components/ui/button';

export type DemoAccount = { email: string; name: string; roles: string[]; password: string };

/** Local only: the seeded accounts of this tenant. Choosing one fills the sign-in form; the person still signs in themselves. */
defineProps<{ accounts: DemoAccount[] }>();
const emit = defineEmits<{ choose: [account: DemoAccount] }>();
const open = defineModel<boolean>('open', { default: false });

let chosen = false;

function choose(account: DemoAccount): void {
    chosen = true;
    open.value = false;
    emit('choose', account);
}

/** After a choice the page moves focus to Sign in, so the dialog must not hand it back to the button that opened it. */
function onCloseAutoFocus(event: Event): void {
    if (chosen) {
        event.preventDefault();
        chosen = false;
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <DialogContent class="fixed top-[10vh] left-1/2 z-50 flex max-h-[80vh] w-[520px] max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-col rounded-panel border border-line bg-surface text-ink shadow-float outline-none" @close-auto-focus="onCloseAutoFocus">
                <div class="flex items-start justify-between gap-4 border-b border-line px-4 py-3">
                    <div>
                        <DialogTitle class="text-section font-semibold">Demo accounts</DialogTitle>
                        <DialogDescription class="text-ui text-ink-2">Seeded accounts in this local database. Choose one to fill the sign-in form.</DialogDescription>
                    </div>
                    <DialogClose class="inline-flex size-8 shrink-0 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" aria-label="Close"><X :size="16" :stroke-width="1.5" /></DialogClose>
                </div>
                <ul v-if="accounts.length" class="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
                    <li v-for="account in accounts" :key="account.email" class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <div class="min-w-0">
                            <p class="text-ui font-medium">{{ account.roles.length ? account.roles.join(', ') : account.name }}</p>
                            <p class="truncate text-dense text-ink-2">{{ account.email }}</p>
                        </div>
                        <Button variant="secondary" size="sm" :aria-label="`Use ${account.email}`" @click="choose(account)">Use</Button>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-ui text-ink-2">No seeded accounts yet. Run <code>composer db:fresh</code> to create them.</p>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
