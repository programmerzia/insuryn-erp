import { describe, expect, it } from 'vitest';
import { formatDateTime, formatMoney, formatMonth } from '@/lib/format';
import { producerDraft } from '@/lib/producerCreate';

/** Gap audit GA-33 and GA-36: one month and timestamp format, negatives in parentheses, a producer draft without a date. */
describe('months and timestamps (GA-36)', () => {
    it('writes a month as "Sep 2026", never "Sept", or in full when asked', () => {
        expect(formatMonth('2026-09-01')).toBe('Sep 2026');
        expect(formatMonth('2026-09')).toBe('Sep 2026');
        expect(formatMonth('2027-06-30', 'long')).toBe('June 2027');
        expect(formatMonth('')).toBe('');
        expect(formatMonth('soon')).toBe('soon');
    });

    it('writes stored timestamps as a date and a Dhaka time', () => {
        expect(formatDateTime('2026-09-14 07:43:57+00')).toBe('14 Sep 2026, 13:43');
        expect(formatDateTime('2026-09-14T20:10:00+00:00')).toBe('15 Sep 2026, 02:10');
        expect(formatDateTime('2026-09-14T07:43:57.000000Z')).toBe('14 Sep 2026, 13:43');
        expect(formatDateTime('2026-09-14')).toBe('14 Sep 2026');
        expect(formatDateTime(null)).toBe('');
        expect(formatDateTime('not a time')).toBe('not a time');
    });

    it('keeps negatives in parentheses', () => {
        expect(formatMoney('-1,000.00')).toBe('(1,000.00)');
    });
});

describe('producer draft (GA-33)', () => {
    it('starts with empty dates instead of throwing when today is not known yet', () => {
        expect(() => producerDraft('', '', '')).not.toThrow();
        expect(producerDraft('', 'b', '')).toMatchObject({ issued_on: '', expires_on: '' });
        expect(producerDraft('Jamal', 'b', '2026-09-15')).toMatchObject({ issued_on: '2026-09-15', expires_on: '2027-09-14' });
    });
});
