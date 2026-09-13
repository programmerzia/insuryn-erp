<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuPortal } from 'reka-ui';

/** A floating menu: the one shadow level (brief §2), 6px panel radius. */
withDefaults(defineProps<{ align?: 'start' | 'center' | 'end'; width?: string }>(), { align: 'end', width: 'w-60' });
</script>

<template>
    <DropdownMenuPortal>
        <DropdownMenuContent :align="align" :side-offset="4" :class="['z-50 rounded-panel border border-line bg-surface p-1 text-ui text-ink shadow-float', width]">
            <slot />
        </DropdownMenuContent>
    </DropdownMenuPortal>
</template>
