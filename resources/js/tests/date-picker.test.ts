import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import { addMonths, addYears, calendarKey, calendarLocale, monthGrid, monthNames, weekdayNames, weekStartFrom, yearOptions } from '@/lib/calendar';

/**
 * UX U1: the product owner could only type dates ("14 Sep 2026"); DateInput keeps typing and its display and gains a calendar.
 * happy-dom limits: no layout, so the popover's floating position is not checked; focus moves are real (document.activeElement); keys are dispatched
 * as KeyboardEvents on the focused element, so the browser's default actions (a click synthesised from Enter on a button) do not happen.
 */
const monday14Sep = new Date(2026, 8, 14, 10, 0, 0);

describe('calendar logic', () => {
    it('lays out six weeks from the configured first weekday', () => {
        const sunday = monthGrid('2026-09-14', 0);
        expect(sunday).toHaveLength(6);
        expect(sunday[0]).toEqual(['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05']);
        expect(monthGrid('2026-09-14', 6)[0]?.[0]).toBe('2026-08-29');
        expect(monthGrid('2026-09-14', 1)[0]?.[0]).toBe('2026-08-31');
        expect(weekStartFrom('Saturday')).toBe(6);
        expect(weekStartFrom('someday')).toBe(0);
    });

    it('moves by day, week, month and year, keeping the day where the month has it', () => {
        expect(calendarKey({ key: 'ArrowRight' }, '2026-09-30', 0)).toEqual({ move: '2026-10-01' });
        expect(calendarKey({ key: 'ArrowUp' }, '2026-09-03', 0)).toEqual({ move: '2026-08-27' });
        expect(calendarKey({ key: 'PageDown' }, '2026-01-31', 0)).toEqual({ move: '2026-02-28' });
        expect(calendarKey({ key: 'PageUp', shiftKey: true }, '2028-02-29', 0)).toEqual({ move: '2027-02-28' });
        expect(calendarKey({ key: 'Home' }, '2026-09-16', 6)).toEqual({ move: '2026-09-12' });
        expect(calendarKey({ key: 'End' }, '2026-09-16', 0)).toEqual({ move: '2026-09-19' });
        expect(calendarKey({ key: 'Enter' }, '2026-09-16', 0)).toEqual({ pick: true });
        expect(calendarKey({ key: 'Escape' }, '2026-09-16', 0)).toEqual({ close: true });
        expect(calendarKey({ key: 'a' }, '2026-09-16', 0)).toBeNull();
        expect(addMonths('2026-03-31', -1)).toBe('2026-02-28');
        expect(addYears('2026-09-14', 1)).toBe('2027-09-14');
    });

    it('stops at min and max', () => {
        expect(calendarKey({ key: 'ArrowRight' }, '2026-09-14', 0, null, '2026-09-14')).toEqual({ move: '2026-09-14' });
        expect(calendarKey({ key: 'PageUp' }, '2026-09-14', 0, '2026-09-01')).toEqual({ move: '2026-09-01' });
        expect(calendarKey({ key: 'Enter' }, '2026-09-20', 0, null, '2026-09-14')).toBeNull();
        expect(yearOptions('2026-09-14', '2020-01-01', '2027-12-31')).toEqual([2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027]);
    });

    it('names months and weekdays in English, or in Bangla with Latin digits', () => {
        expect(monthNames(calendarLocale('en'))[8]).toBe('September');
        expect(weekdayNames(calendarLocale('en'), 6).map((d) => d.long).slice(0, 2)).toEqual(['Saturday', 'Sunday']);
        expect(calendarLocale('bn')).toBe('bn-BD-u-nu-latn');
        expect(monthNames(calendarLocale('bn'))[8]).toBe('সেপ্টেম্বর');
    });
});

describe('DateInput with its calendar', () => {
    let wrapper: VueWrapper | null = null;

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(monday14Sep);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.useRealTimers();
    });

    function host(initial = '', extra: Record<string, unknown> = {}) {
        const value = ref(initial);
        const invalid = ref<string | null>(null);
        const Host = defineComponent({
            setup: () => () => h(DateInput, { id: 'paid_on', modelValue: value.value, 'onUpdate:modelValue': (v: string) => (value.value = v), onInvalid: (m: string | null) => (invalid.value = m), weekStartsOn: 'sunday', ...extra }),
        });
        wrapper = mount(Host, { attachTo: document.body });
        return { value, invalid, input: () => document.getElementById('paid_on') as HTMLInputElement };
    }

    const calendarButton = () => document.querySelector<HTMLButtonElement>('button[aria-label="Choose a date from the calendar"]')!;
    const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]');
    const focusedDate = () => (document.activeElement as HTMLElement | null)?.getAttribute('data-date');
    async function press(key: string, init: KeyboardEventInit = {}): Promise<void> {
        (document.activeElement as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }));
        await flushPromises();
        await nextTick();
    }

    it('still reads what people type and shows it as 14 Sep 2026', async () => {
        const { value, invalid, input } = host();
        input().value = '1.10.26';
        input().dispatchEvent(new Event('input'));
        input().dispatchEvent(new Event('blur'));
        await nextTick();
        expect(value.value).toBe('2026-10-01');
        expect(input().value).toBe('1 Oct 2026');

        input().value = 'soon';
        input().dispatchEvent(new Event('input'));
        input().dispatchEvent(new Event('blur'));
        await nextTick();
        expect(value.value).toBe('2026-10-01');
        expect(invalid.value).toContain('is not a date');
    });

    it('opens from the button inside the field on the selected day, today marked, and picks with a click', async () => {
        const { value, input } = host('2026-09-03');
        expect(calendarButton()).toBeTruthy();
        calendarButton().click();
        await flushPromises();
        await nextTick();
        expect(dialog()).not.toBeNull();
        expect(focusedDate()).toBe('2026-09-03');
        expect(document.querySelector('[aria-selected="true"] button')?.getAttribute('data-date')).toBe('2026-09-03');
        expect(document.querySelector('button[aria-current="date"]')?.getAttribute('data-date')).toBe('2026-09-14');
        expect(document.querySelector('th')?.getAttribute('abbr')).toBe('Sunday');

        document.querySelector<HTMLButtonElement>('button[data-date="2026-09-21"]')!.click();
        await flushPromises();
        await nextTick();
        expect(value.value).toBe('2026-09-21');
        expect(input().value).toBe('21 Sep 2026');
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(input());
    });

    it('is driven by the keyboard: Alt+ArrowDown opens, arrows and PageUp/PageDown move, Enter picks the right day', async () => {
        const { value, input } = host();
        input().focus();
        await press('ArrowDown', { altKey: true });
        expect(dialog()).not.toBeNull();
        expect(focusedDate()).toBe('2026-09-14');

        await press('ArrowRight');
        await press('ArrowDown');
        expect(focusedDate()).toBe('2026-09-22');
        await press('PageDown');
        expect(focusedDate()).toBe('2026-10-22');
        await press('PageUp', { shiftKey: true });
        expect(focusedDate()).toBe('2025-10-22');
        expect(document.querySelector<HTMLSelectElement>('select[aria-label="Year"]')!.value).toBe('2025');
        await press('Enter');
        expect(value.value).toBe('2025-10-22');
        expect(input().value).toBe('22 Oct 2025');
        expect(dialog()).toBeNull();
        expect(document.activeElement).toBe(input());
    });

    it('closes on Escape without changing the date and gives focus back to the field', async () => {
        const { value, input } = host('2026-09-03');
        input().focus();
        await press('ArrowDown', { altKey: true });
        await press('ArrowLeft');
        await press('Escape');
        expect(dialog()).toBeNull();
        expect(value.value).toBe('2026-09-03');
        expect(document.activeElement).toBe(input());
    });

    it('jumps with the month and year choices', async () => {
        host('2026-09-03');
        calendarButton().click();
        await flushPromises();
        const month = document.querySelector<HTMLSelectElement>('select[aria-label="Month"]')!;
        month.value = '1';
        month.dispatchEvent(new Event('change'));
        await nextTick();
        expect(document.querySelector('[role="grid"]')?.getAttribute('aria-label')).toBe('February 2026');
        expect(document.querySelector<HTMLButtonElement>('button[data-date="2026-02-03"]')?.tabIndex).toBe(0);
    });

    it('honours max: later days are disabled, keys stop at it, and a typed later date is refused', async () => {
        const { value, invalid, input } = host('', { max: '2026-09-14' });
        input().focus();
        await press('ArrowDown', { altKey: true });
        expect(document.querySelector<HTMLButtonElement>('button[data-date="2026-09-15"]')?.disabled).toBe(true);
        expect(document.querySelector<HTMLButtonElement>('button[aria-label="Next month"]')?.disabled).toBe(true);
        await press('ArrowRight');
        expect(focusedDate()).toBe('2026-09-14');
        await press('Escape');

        input().value = '+1';
        input().dispatchEvent(new Event('input'));
        input().dispatchEvent(new Event('blur'));
        await nextTick();
        expect(value.value).toBe('');
        expect(invalid.value).toBe('15 Sep 2026 is outside the dates allowed here. Choose 14 Sep 2026 or earlier.');
        expect(input().getAttribute('aria-invalid')).toBe('true');
    });

    it('honours min and starts the week where configured', async () => {
        host('', { min: '2026-09-10', weekStartsOn: 'saturday' });
        calendarButton().click();
        await flushPromises();
        expect(document.querySelector('th')?.getAttribute('abbr')).toBe('Saturday');
        expect(document.querySelector<HTMLButtonElement>('button[data-date="2026-09-09"]')?.disabled).toBe(true);
        await press('PageUp');
        expect(focusedDate()).toBe('2026-09-10');
    });
});
