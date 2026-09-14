<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CalendarDays } from 'lucide-vue-next';
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, nextTick, ref, useAttrs, watch } from 'vue';
import DateCalendar from '@/components/forms/DateCalendar.vue';
import { calendarLocale, clamp, fromIso, todayIso, weekStartFrom } from '@/lib/calendar';
import { parseDateInput } from '@/lib/dates';
import { useField } from '@/lib/field';
import { formatDate } from '@/lib/format';
import { usePreferences } from '@/lib/preferences';

/**
 * Brief §4 keyboard date: type "12 Sep 2026", "12/09/2026", `t` for today or `+3` for three days from today. Shows "12 Sep 2026"; the
 * model is the ISO date the server expects. Unreadable text stays visible and `invalid` explains it.
 *
 * UX U1: the calendar button inside the field (or Alt+ArrowDown in it) opens a month grid: arrows move a day or week, PageUp/PageDown a month
 * (Shift: a year), Enter picks, Escape closes, and focus comes back to the field. `min` / `max` (ISO) disable days outside them and refuse a
 * typed date outside them. The week starts on `erp.ui.week_starts_on` (A-165).
 */
defineOptions({ inheritAttrs: false });
const props = defineProps<{ id?: string; placeholder?: string; min?: string | null; max?: string | null; weekStartsOn?: string }>();
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ invalid: [message: string | null] }>();
const attrs = useAttrs();
const field = useField(props.id);
const text = ref(formatDate(model.value));
const rangeError = ref(false);
const open = ref(false);
const focused = ref(todayIso());
const today = ref(todayIso());
const input = ref<HTMLInputElement | null>(null);
const calendar = ref<InstanceType<typeof DateCalendar> | null>(null);

/** The shared settings and the user's language, when the field sits in an Inertia page (a bare mount in a test has neither). */
function shared(): { weekStartsOn: unknown; language: string | undefined } {
    const settings: { weekStartsOn: unknown; language: string | undefined } = { weekStartsOn: undefined, language: undefined };
    try {
        const props = usePage()?.props as Record<string, unknown> | undefined;
        if (!props) return settings;
        settings.weekStartsOn = (props.calendar as { week_starts_on?: string } | undefined)?.week_starts_on;
        settings.language = usePreferences().locale;
    } catch {
        // Outside an Inertia page: the defaults.
    }
    return settings;
}
const settings = shared();
const weekStart = computed(() => weekStartFrom(props.weekStartsOn ?? settings.weekStartsOn));
const locale = computed(() => calendarLocale(settings.language));

const wrapperAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const inputAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;
    return rest;
});
const invalid = computed(() => rangeError.value || field.value['aria-invalid'] || (attrs['aria-invalid'] as boolean | undefined) || undefined);

watch(model, (value) => {
    if (parseDateInput(text.value) !== value) text.value = formatDate(value);
});

function outOfRange(iso: string): string | null {
    if (props.min && iso < props.min) return `Choose ${formatDate(props.min)} or later.`;
    if (props.max && iso > props.max) return `Choose ${formatDate(props.max)} or earlier.`;
    return null;
}

function commit(): void {
    rangeError.value = false;
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
    const range = outOfRange(parsed);
    if (range !== null) {
        rangeError.value = true;
        emit('invalid', `${formatDate(parsed)} is outside the dates allowed here. ${range}`);
        return;
    }
    model.value = parsed;
    text.value = formatDate(parsed);
    emit('invalid', null);
}

function onOpenChange(value: boolean): void {
    if (value) {
        today.value = todayIso();
        const typed = parseDateInput(text.value);
        focused.value = clamp(typed ?? (fromIso(model.value) ? model.value : today.value), props.min, props.max);
    }
    open.value = value;
}

function openPicker(): void {
    onOpenChange(true);
}

function onInputKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' && event.altKey) {
        event.preventDefault();
        openPicker();
    }
}

function pick(iso: string): void {
    rangeError.value = false;
    model.value = iso;
    text.value = formatDate(iso);
    emit('invalid', null);
    open.value = false;
    void nextTick(() => input.value?.focus());
}

function close(): void {
    open.value = false;
    void nextTick(() => input.value?.focus());
}
</script>

<template>
    <PopoverRoot :open="open" @update:open="onOpenChange">
        <PopoverAnchor as-child>
            <div class="relative" v-bind="wrapperAttrs">
                <input
                    v-bind="{ ...inputAttrs, ...field }"
                    ref="input"
                    v-model="text"
                    type="text"
                    autocomplete="off"
                    :aria-invalid="invalid"
                    :placeholder="placeholder ?? 'e.g. 12 Sep 2026 or t'"
                    class="h-8 w-full rounded-control border border-line-control bg-surface pr-8 pl-2 text-body text-ink tabular-nums placeholder:text-ink-2 aria-[invalid=true]:border-danger"
                    @blur="commit"
                    @keydown.enter="commit"
                    @keydown="onInputKeydown"
                />
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        :aria-label="field.id ? 'Choose a date from the calendar' : 'Open calendar'"
                        :aria-controls="field.id ? `${field.id}-calendar` : undefined"
                        class="absolute inset-y-0 right-0 inline-flex w-8 items-center justify-center rounded-control text-ink-2 hover:text-ink"
                        @mousedown.prevent
                    >
                        <CalendarDays :size="16" :stroke-width="1.5" aria-hidden="true" />
                    </button>
                </PopoverTrigger>
            </div>
        </PopoverAnchor>
        <PopoverPortal>
            <PopoverContent
                :id="field.id ? `${field.id}-calendar` : undefined"
                align="start"
                :side-offset="4"
                :collision-padding="8"
                role="dialog"
                aria-label="Choose a date"
                class="z-50 rounded-panel border border-line bg-surface p-2 shadow-float"
                @open-auto-focus.prevent="calendar?.focusDay()"
                @close-auto-focus.prevent="input?.focus()"
            >
                <DateCalendar ref="calendar" v-model:focused="focused" :selected="model" :today="today" :week-start="weekStart" :locale="locale" :min="min" :max="max" @pick="pick" @close="close" />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
