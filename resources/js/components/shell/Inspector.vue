<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import Kbd from '@/components/ui/Kbd.vue';
import { shortcutKeys, useShortcut } from '@/lib/shortcuts';

/**
 * Detail / inspector panel (brief §3): opens on row select, Esc closes, Ctrl+Enter runs the primary action. Tabs Details · Accounting ·
 * History · Files are slots; a tab without a slot is not shown.
 */
const props = defineProps<{ title: string; subtitle?: string; primaryLabel?: string }>();
const emit = defineEmits<{ close: []; primary: [] }>();
const tab = defineModel<string>('tab', { default: 'details' });
const tabs = [
    { value: 'details', label: 'Details' },
    { value: 'accounting', label: 'Accounting' },
    { value: 'history', label: 'History' },
    { value: 'files', label: 'Files' },
] as const;

useShortcut('inspector.close', () => emit('close'));
useShortcut('inspector.primary', () => props.primaryLabel && emit('primary'), { allowInInputs: true });
</script>

<template>
    <TabsRoot v-model="tab" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-start gap-2 border-b border-line px-4 pt-3">
            <div class="min-w-0 flex-1 pb-2">
                <h2 class="truncate text-section font-semibold">{{ title }}</h2>
                <p v-if="subtitle" class="truncate text-ui text-ink-2">{{ subtitle }}</p>
            </div>
            <button type="button" class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :title="`Close (${shortcutKeys('inspector.close')})`" aria-label="Close the inspector" @click="emit('close')">
                <X :size="16" :stroke-width="1.5" />
            </button>
        </div>
        <TabsList class="flex gap-4 border-b border-line px-4" aria-label="Inspector sections">
            <template v-for="item in tabs" :key="item.value">
                <TabsTrigger v-if="$slots[item.value]" :value="item.value" class="-mb-px h-8 border-b-2 border-transparent text-ui text-ink-2 hover:text-ink data-[state=active]:border-accent data-[state=active]:text-ink">
                    {{ item.label }}
                </TabsTrigger>
            </template>
        </TabsList>
        <div class="min-h-0 flex-1 overflow-y-auto">
            <template v-for="item in tabs" :key="item.value">
                <TabsContent v-if="$slots[item.value]" :value="item.value" class="p-4 outline-none"><slot :name="item.value" /></TabsContent>
            </template>
        </div>
        <div v-if="primaryLabel" class="flex items-center gap-2 border-t border-line px-4 py-2">
            <slot name="actions" />
            <button type="button" class="ml-auto inline-flex h-8 items-center gap-2 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="emit('primary')">
                {{ primaryLabel }} <Kbd :keys="shortcutKeys('inspector.primary')" class="text-accent-ink" />
            </button>
        </div>
    </TabsRoot>
</template>
