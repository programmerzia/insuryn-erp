<script setup lang="ts">
/** The CoreBari mark (corebari/brand/svg/mark-on-light.svg), drawn with theme tokens so it holds in both modes. */
withDefaults(defineProps<{ size?: number }>(), { size: 20 });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 64 64" aria-hidden="true">
        <path d="M32 5 L49 21 H15 Z" fill="var(--accent)" />
        <rect x="11" y="27" width="42" height="12" rx="2" fill="var(--ink)" />
        <rect x="5" y="43" width="54" height="12" rx="2" fill="var(--ink)" />
    </svg>
</template>
