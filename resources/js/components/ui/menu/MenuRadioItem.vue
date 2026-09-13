<script setup lang="ts">
import { Check } from 'lucide-vue-next';
import { DropdownMenuItemIndicator, DropdownMenuRadioItem } from 'reka-ui';

defineProps<{ value: string }>();
</script>

<template>
    <DropdownMenuRadioItem :value="value" class="flex h-8 cursor-default items-center gap-2 rounded-control pr-2 pl-7 outline-none select-none data-[highlighted]:bg-surface-2 relative">
        <DropdownMenuItemIndicator class="absolute left-2 inline-flex"><Check :size="14" :stroke-width="1.5" /></DropdownMenuItemIndicator>
        <slot />
    </DropdownMenuRadioItem>
</template>
