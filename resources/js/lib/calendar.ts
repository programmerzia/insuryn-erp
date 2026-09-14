/**
 * UX U1: the calendar behind DateInput's picker. Pure functions over ISO dates (`Y-m-d`, the value contract DateInput keeps), no Vue and no time zones:
 * dates are built from their parts in the browser's calendar, the same way parseDateInput reads `t`.
 */

/** 0 = Sunday … 6 = Saturday, as Date#getDay counts. */
export type Weekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

export const WEEKDAYS: Record<string, Weekday> = { sunday: 0, monday: 1, tuesday: 2, wednesday: 3, thursday: 4, friday: 5, saturday: 6 };

/** ASSUMPTION: A-165 — the calendar week starts on Sunday (Bangladesh's working week, Sunday to Thursday; CLDR bn-BD); `erp.ui.week_starts_on`. */
export const DEFAULT_WEEK_START: Weekday = 0;

export function weekStartFrom(name: unknown): Weekday {
    return typeof name === 'string' && name.toLowerCase() in WEEKDAYS ? (WEEKDAYS[name.toLowerCase()] as Weekday) : DEFAULT_WEEK_START;
}

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

export function toIso(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The Date for an ISO day (local midnight), or null when the text is not a real day. */
export function fromIso(iso: string | null | undefined): Date | null {
    const match = (iso ?? '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return toIso(date) === iso ? date : null;
}

export function todayIso(now: Date = new Date()): string {
    return toIso(now);
}

export function addDays(iso: string, days: number): string {
    const date = fromIso(iso) ?? new Date();
    return toIso(new Date(date.getFullYear(), date.getMonth(), date.getDate() + days));
}

/** Months later or earlier, keeping the day where the month has it (31 Jan + 1 month = 28 or 29 Feb). */
export function addMonths(iso: string, months: number): string {
    const date = fromIso(iso) ?? new Date();
    const first = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const last = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
    return toIso(new Date(first.getFullYear(), first.getMonth(), Math.min(date.getDate(), last)));
}

export function addYears(iso: string, years: number): string {
    return addMonths(iso, years * 12);
}

/** ISO dates compare as text. */
export function inRange(iso: string, min?: string | null, max?: string | null): boolean {
    return !(min && iso < min) && !(max && iso > max);
}

export function clamp(iso: string, min?: string | null, max?: string | null): string {
    if (min && iso < min) return min;
    if (max && iso > max) return max;
    return iso;
}

/** Six weeks of ISO days covering the month of `iso`, each week starting on `weekStart`. */
export function monthGrid(iso: string, weekStart: Weekday): string[][] {
    const date = fromIso(iso) ?? new Date();
    const first = new Date(date.getFullYear(), date.getMonth(), 1);
    const lead = (first.getDay() - weekStart + 7) % 7;
    const weeks: string[][] = [];
    for (let w = 0; w < 6; w++) {
        weeks.push(Array.from({ length: 7 }, (_, d) => toIso(new Date(first.getFullYear(), first.getMonth(), 1 - lead + w * 7 + d))));
    }
    return weeks;
}

export function sameMonth(a: string, b: string): boolean {
    return a.slice(0, 7) === b.slice(0, 7);
}

/** What a key does in the open calendar: the day to move to, pick the focused day, close, or nothing (the key is left alone). */
export type CalendarKeyResult = { move: string } | { pick: true } | { close: true } | null;

export interface CalendarKey {
    key: string;
    shiftKey?: boolean;
}

/**
 * Brief §4 keyboard-first: arrows move a day or a week, PageUp/PageDown a month, with Shift a year, Home/End the start and end of the week,
 * Enter or Space picks, Escape closes. A move never leaves min/max: it stops at the bound.
 */
export function calendarKey(event: CalendarKey, focused: string, weekStart: Weekday, min?: string | null, max?: string | null): CalendarKeyResult {
    const weekday = (fromIso(focused) ?? new Date()).getDay();
    const offset = (weekday - weekStart + 7) % 7;
    let next: string | null = null;
    switch (event.key) {
        case 'ArrowLeft': next = addDays(focused, -1); break;
        case 'ArrowRight': next = addDays(focused, 1); break;
        case 'ArrowUp': next = addDays(focused, -7); break;
        case 'ArrowDown': next = addDays(focused, 7); break;
        case 'PageUp': next = event.shiftKey ? addYears(focused, -1) : addMonths(focused, -1); break;
        case 'PageDown': next = event.shiftKey ? addYears(focused, 1) : addMonths(focused, 1); break;
        case 'Home': next = addDays(focused, -offset); break;
        case 'End': next = addDays(focused, 6 - offset); break;
        case 'Enter':
        case ' ': return inRange(focused, min, max) ? { pick: true } : null;
        case 'Escape': return { close: true };
        default: return null;
    }
    return { move: clamp(next, min, max) };
}

/** The locale the calendar's words use: English, or Bangla (with Latin digits) when the user reads the help in Bangla. ASSUMPTION: A-166. */
export function calendarLocale(language: 'en' | 'bn' | string | undefined): string {
    return language === 'bn' ? 'bn-BD-u-nu-latn' : 'en-GB';
}

export function monthNames(locale: string): string[] {
    const format = new Intl.DateTimeFormat(locale, { month: 'long' });
    return Array.from({ length: 12 }, (_, m) => format.format(new Date(2026, m, 1)));
}

/** Short and long weekday names in calendar order. */
export function weekdayNames(locale: string, weekStart: Weekday): { short: string; long: string }[] {
    const short = new Intl.DateTimeFormat(locale, { weekday: 'short' });
    const long = new Intl.DateTimeFormat(locale, { weekday: 'long' });
    // 6 Sep 2026 is a Sunday.
    return Array.from({ length: 7 }, (_, i) => {
        const day = new Date(2026, 8, 6 + ((weekStart + i) % 7));
        return { short: short.format(day), long: long.format(day) };
    });
}

/** "Monday 14 September 2026" for a day's accessible name. */
export function longDate(iso: string, locale: string): string {
    const date = fromIso(iso);
    return date ? new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date) : iso;
}

/** Years the quick navigation offers: around the shown year, widened to min/max when they are set. */
export function yearOptions(shown: string, min?: string | null, max?: string | null, span = 10): number[] {
    const year = Number(shown.slice(0, 4));
    const from = min ? Number(min.slice(0, 4)) : year - span;
    const to = max ? Number(max.slice(0, 4)) : year + span;
    const years: number[] = [];
    for (let y = Math.min(from, year); y <= Math.max(to, year); y++) years.push(y);
    return years;
}
