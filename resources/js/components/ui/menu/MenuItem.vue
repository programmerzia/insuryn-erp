<script setup lang="ts">
import { DropdownMenuItem } from 'reka-ui';
import Kbd from '@/components/ui/Kbd.vue';
import { type ShortcutId, shortcutKeys } from '@/lib/shortcuts';

/** A menu action. Brief §4: every action in a menu shows its shortcut, read from the shortcuts registry. */
defineProps<{ shortcut?: ShortcutId }>();
defineEmits<{ select: [event: Event] }>();
</script>

<template>
    <DropdownMenuItem class="flex h-8 cursor-default items-center gap-2 rounded-control px-2 outline-none select-none data-[highlighted]:bg-surface-2" @select="$emit('select', $event)">
        <slot />
        <Kbd v-if="shortcut" class="ml-auto" :keys="shortcutKeys(shortcut)" />
    </DropdownMenuItem>
</template>
