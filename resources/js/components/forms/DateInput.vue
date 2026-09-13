<script setup lang="ts">
import { ref, watch } from 'vue';
import { parseDateInput } from '@/lib/dates';
import { useField } from '@/lib/field';
import { formatDate } from '@/lib/format';

/**
 * Brief §4 keyboard date: type "12 Sep 2026", "12/09/2026", `t` for today or `+3` for three days from today. Shows "12 Sep 2026"; the
 * model is the ISO date the server expects. Unreadable text stays visible and `invalid` explains it.
 */
const props = defineProps<{ id?: string; placeholder?: string }>();
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ invalid: [message: string | null] }>();
const field = useField(props.id);
const text = ref(formatDate(model.value));

watch(model, (value) => {
    if (parseDateInput(text.value) !== value) text.value = formatDate(value);
});

function commit(): void {
    if (text.value.trim() === '') {
        model.value = '';
        emit('invalid', null);
        return;
    }
    const parsed = parseDateInput(text.value);
    if (parsed === null) {
        emit('invalid', `“${text.value}” is not a date. Type 12 Sep 2026, t for today or +3 for three days from today.`);
        return;
    }
    model.value = parsed;
    text.value = formatDate(parsed);
    emit('invalid', null);
}
</script>

<template>
    <input
        v-bind="field"
        v-model="text"
        type="text"
        autocomplete="off"
        :placeholder="placeholder ?? 'e.g. 12 Sep 2026 or t'"
        class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-body text-ink tabular-nums placeholder:text-ink-2 aria-[invalid=true]:border-danger"
        @blur="commit"
        @keydown.enter="commit"
    />
</template>
