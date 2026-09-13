<script setup lang="ts">
import { useField } from '@/lib/field';
import { normaliseMoneyInput, stepMoney } from '@/lib/money';

/** Brief §4 money input: right-aligned tabular figures, formatted on blur, ↑/↓ add or take away 1,000. The value stays a string in major units. */
const props = withDefaults(defineProps<{ allowNegative?: boolean; placeholder?: string; id?: string }>(), { allowNegative: false, placeholder: '0.00', id: undefined });
const model = defineModel<string>({ default: '' });
const field = useField(props.id);
const STEP = 1000n * 100n;
</script>

<template>
    <input
        v-bind="field"
        v-model="model"
        type="text"
        inputmode="decimal"
        autocomplete="off"
        :placeholder="placeholder"
        class="h-8 w-full rounded-control border border-line-control bg-surface px-2 text-right text-body text-ink tabular-nums placeholder:text-ink-2 aria-[invalid=true]:border-danger"
        @blur="model = normaliseMoneyInput(model, allowNegative)"
        @keydown.up.prevent="model = stepMoney(model, STEP, allowNegative)"
        @keydown.down.prevent="model = stepMoney(model, -STEP, allowNegative)"
    />
</template>
