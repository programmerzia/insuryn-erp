<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Kbd from '@/components/ui/Kbd.vue';
import { shortcutKeys, useShortcut } from '@/lib/shortcuts';
import { useUnsavedGuard } from '@/lib/unsaved';
import type { SharedProps } from '@/types/shared';

/**
 * Brief §4 form: one column, 560px, labels above; Tab order is reading order. Ctrl+Enter submits, Ctrl+S saves a draft (when the form
 * offers drafts), Esc cancels with the unsaved-changes guard. Business-rule refusals from the server show above the fields.
 */
const props = withDefaults(defineProps<{ submitLabel: string; cancelHref: string; dirty: boolean; processing?: boolean; drafts?: boolean; wide?: boolean; error?: string }>(), {
    processing: false, drafts: false, wide: false, error: undefined,
});
const emit = defineEmits<{ submit: []; saveDraft: [] }>();
const page = usePage<SharedProps>();
const formError = computed(() => props.error ?? page.props.errors.form);
const guard = useUnsavedGuard(() => props.dirty && !props.processing);

useShortcut('form.submit', () => emit('submit'), { allowInInputs: true });
useShortcut('form.save', () => props.drafts && emit('saveDraft'), { allowInInputs: true });
useShortcut('form.cancel', () => void guard.leave(props.cancelHref), { allowInInputs: true });
</script>

<template>
    <form class="grid gap-4" :class="wide ? 'max-w-[880px]' : 'max-w-[560px]'" novalidate @submit.prevent="emit('submit')">
        <p v-if="formError" class="border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ formError }}</p>
        <slot />
        <div class="flex items-center gap-2 border-t border-line pt-4">
            <button type="submit" :disabled="processing" class="inline-flex h-8 items-center gap-1.5 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50">
                {{ submitLabel }} <Kbd :keys="shortcutKeys('form.submit')" class="text-accent-ink" />
            </button>
            <button v-if="drafts" type="button" class="inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="emit('saveDraft')">
                Save draft <Kbd :keys="shortcutKeys('form.save')" />
            </button>
            <button type="button" class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="guard.leave(cancelHref)">
                Cancel <Kbd :keys="shortcutKeys('form.cancel')" />
            </button>
        </div>
    </form>
</template>
