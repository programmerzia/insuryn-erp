<script setup lang="ts">
defineProps<{ message?: string }>();
</script>

<template>
    <p v-if="message" class="text-ui text-danger" role="alert">{{ message }}</p>
</template>
