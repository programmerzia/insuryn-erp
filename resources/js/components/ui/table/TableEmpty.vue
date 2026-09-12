<script setup lang="ts">
import TableCell from './TableCell.vue';
import TableRow from './TableRow.vue';

defineProps<{ colspan: number }>();
</script>

<template>
    <TableRow class="hover:bg-transparent">
        <TableCell :colspan="colspan" class="py-10 text-center text-ivory-dim">
            <slot />
        </TableCell>
    </TableRow>
</template>
