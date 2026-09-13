<script setup lang="ts">
import { computed, ref } from 'vue';
import { savePreference, usePreferences } from '@/lib/preferences';

/** Brief §3: list + inspector split with a draggable, remembered divider. The inspector shows while `open`. */
const props = withDefaults(defineProps<{ id: string; open: boolean; defaultWidth?: number; min?: number }>(), { defaultWidth: 440, min: 320 });
const preferences = usePreferences();
const container = ref<HTMLElement | null>(null);
const dragging = ref(false);
const width = computed(() => preferences.splits[props.id] ?? props.defaultWidth);

function clamp(value: number): number {
    const max = Math.max(props.min, (container.value?.clientWidth ?? 1600) - 360);
    return Math.round(Math.min(Math.max(value, props.min), Math.min(max, 1400)));
}

function start(event: PointerEvent): void {
    const handle = event.currentTarget as HTMLElement;
    handle.setPointerCapture(event.pointerId);
    dragging.value = true;
}

function move(event: PointerEvent): void {
    if (!dragging.value || !container.value) return;
    const right = container.value.getBoundingClientRect().right;
    savePreference(`splits.${props.id}`, clamp(right - event.clientX));
}

function nudge(delta: number): void {
    savePreference(`splits.${props.id}`, clamp(width.value + delta));
}
</script>

<template>
    <div ref="container" class="flex min-h-0 flex-1" :class="{ 'select-none': dragging }">
        <div class="flex min-w-0 flex-1 flex-col"><slot /></div>
        <template v-if="open">
            <div
                role="separator"
                aria-orientation="vertical"
                aria-label="Resize the inspector"
                :aria-valuenow="width"
                tabindex="0"
                class="w-1 shrink-0 cursor-col-resize border-l border-line hover:bg-focus focus-visible:bg-focus"
                :class="{ 'bg-focus': dragging }"
                @pointerdown="start"
                @pointermove="move"
                @pointerup="dragging = false"
                @keydown.left.prevent="nudge(16)"
                @keydown.right.prevent="nudge(-16)"
            />
            <aside class="flex min-h-0 shrink-0 flex-col bg-surface" :style="{ width: `${width}px` }"><slot name="inspector" /></aside>
        </template>
    </div>
</template>
