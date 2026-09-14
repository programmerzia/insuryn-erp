<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { addMonths, calendarKey, clamp, fromIso, inRange, longDate, monthGrid, monthNames, sameMonth, weekdayNames, yearOptions, type Weekday } from '@/lib/calendar';
import { cn } from '@/lib/utils';

/**
 * UX U1: the month grid inside DateInput's picker. One day is focusable at a time (roving tabindex); the keys are in lib/calendar (arrows, PageUp/PageDown,
 * Shift for years, Home/End, Enter picks, Escape closes). Today is ringed, the selected day filled, days outside min/max disabled.
 */
const props = defineProps<{ selected: string; today: string; weekStart: Weekday; locale: string; min?: string | null; max?: string | null }>();
const focused = defineModel<string>('focused', { required: true });
const emit = defineEmits<{ pick: [iso: string]; close: [] }>();
const grid = ref<HTMLElement | null>(null);

const weeks = computed(() => monthGrid(focused.value, props.weekStart));
const weekdays = computed(() => weekdayNames(props.locale, props.weekStart));
const months = computed(() => monthNames(props.locale));
const years = computed(() => yearOptions(focused.value, props.min, props.max));
const month = computed(() => (fromIso(focused.value)?.getMonth() ?? 0));
const year = computed(() => Number(focused.value.slice(0, 4)));
const heading = computed(() => `${months.value[month.value]} ${year.value}`);
const prevDisabled = computed(() => !!props.min && addMonths(focused.value, -1).slice(0, 7) < props.min.slice(0, 7));
const nextDisabled = computed(() => !!props.max && addMonths(focused.value, 1).slice(0, 7) > props.max.slice(0, 7));
const todayAllowed = computed(() => inRange(props.today, props.min, props.max));

/** Focuses the focused day's button (after the grid shows its month). */
async function focusDay(): Promise<void> {
    await nextTick();
    grid.value?.querySelector<HTMLButtonElement>(`button[data-date="${focused.value}"]`)?.focus();
}

function move(iso: string, focus = true): void {
    focused.value = clamp(iso, props.min, props.max);
    if (focus) void focusDay();
}

function onKeydown(event: KeyboardEvent): void {
    const result = calendarKey(event, focused.value, props.weekStart, props.min, props.max);
    if (result === null) return;
    event.preventDefault();
    event.stopPropagation();
    if ('move' in result) move(result.move);
    else if ('pick' in result) emit('pick', focused.value);
    else emit('close');
}

function showMonth(m: number): void {
    move(addMonths(focused.value, m - month.value), false);
}

function showYear(y: number): void {
    move(addMonths(focused.value, (y - year.value) * 12), false);
}

defineExpose({ focusDay });
</script>

<template>
    <div class="grid w-[17rem] gap-2 text-ui text-ink" @keydown.esc.stop.prevent="emit('close')">
        <div class="flex items-center gap-1">
            <button type="button" class="inline-flex size-7 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink disabled:opacity-50" aria-label="Previous month" :disabled="prevDisabled" @click="move(addMonths(focused, -1), false)">
                <ChevronLeft :size="16" />
            </button>
            <select :value="month" aria-label="Month" class="h-7 min-w-0 flex-1 rounded-control border border-line-control bg-surface px-1 text-ui" @change="showMonth(Number(($event.target as HTMLSelectElement).value))">
                <option v-for="(name, m) in months" :key="m" :value="m">{{ name }}</option>
            </select>
            <select :value="year" aria-label="Year" class="h-7 w-[4.75rem] rounded-control border border-line-control bg-surface px-1 text-ui tabular-nums" @change="showYear(Number(($event.target as HTMLSelectElement).value))">
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
            <button type="button" class="inline-flex size-7 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink disabled:opacity-50" aria-label="Next month" :disabled="nextDisabled" @click="move(addMonths(focused, 1), false)">
                <ChevronRight :size="16" />
            </button>
        </div>
        <table ref="grid" role="grid" :aria-label="heading" class="w-full table-fixed border-separate border-spacing-0.5 text-center" @keydown="onKeydown">
            <thead>
                <tr>
                    <th v-for="d in weekdays" :key="d.long" scope="col" :abbr="d.long" class="h-6 text-dense font-normal text-ink-2">{{ d.short }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(week, w) in weeks" :key="w">
                    <td v-for="day in week" :key="day" role="gridcell" :aria-selected="day === selected">
                        <button
                            type="button"
                            :data-date="day"
                            :tabindex="day === focused ? 0 : -1"
                            :disabled="!inRange(day, min, max)"
                            :aria-label="longDate(day, locale)"
                            :aria-current="day === today ? 'date' : undefined"
                            :class="cn(
                                'inline-flex h-7 w-full items-center justify-center rounded-control tabular-nums hover:bg-surface-2 disabled:pointer-events-none disabled:opacity-40',
                                !sameMonth(day, focused) && 'text-ink-2',
                                day === today && 'font-semibold ring-1 ring-accent ring-inset',
                                day === selected && 'bg-accent text-accent-ink hover:bg-accent-hover',
                            )"
                            @click="emit('pick', day)"
                        >
                            {{ Number(day.slice(8)) }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="flex items-center justify-between gap-2 border-t border-line pt-2">
            <p class="text-dense text-ink-2">Arrow keys, PgUp/PgDn, Enter</p>
            <button type="button" class="h-7 rounded-control px-2 text-ui font-medium text-accent-text hover:bg-surface-2 disabled:opacity-50" :disabled="!todayAllowed" @click="emit('pick', today)">Today</button>
        </div>
    </div>
</template>
