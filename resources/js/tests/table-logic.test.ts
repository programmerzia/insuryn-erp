import { describe, expect, it } from 'vitest';
import { formatDate, formatMoney } from '@/lib/format';
import { formatMinor, parseMoney, sumMoney } from '@/lib/money';
import { decodeTableState, encodeTableState, matchesFilter } from '@/lib/table-state';

describe('money (never floats)', () => {
    it('parses formatted amounts to minor units, including parentheses and minus', () => {
        expect(parseMoney('1,234.56')).toBe(123456n);
        expect(parseMoney('(1,234.56)')).toBe(-123456n);
        expect(parseMoney('-10,000.00')).toBe(-1000000n);
        expect(parseMoney('90071992547409.93')).toBe(9007199254740993n); // beyond Number precision
        expect(parseMoney('12')).toBe(1200n);
        expect(parseMoney('1.5')).toBe(150n);
        expect(parseMoney('')).toBeNull();
        expect(parseMoney('1.234')).toBeNull();
        expect(parseMoney('abc')).toBeNull();
    });

    it('formats minor units with separators and negatives in parentheses', () => {
        expect(formatMinor(123456n)).toBe('1,234.56');
        expect(formatMinor(-123456n)).toBe('(1,234.56)');
        expect(formatMinor(5n)).toBe('0.05');
        expect(formatMinor(0n)).toBe('0.00');
        expect(sumMoney(['0.10', '0.20', '(0.05)'])).toBe(25n);
    });

    it('shows server amounts the brief way', () => {
        expect(formatMoney('-10,000.00')).toBe('(10,000.00)');
        expect(formatMoney('10,000.00')).toBe('10,000.00');
        expect(formatMoney(null)).toBe('');
    });
});

describe('dates', () => {
    it('writes business dates as 12 Sep 2026 without timezone drift', () => {
        expect(formatDate('2026-09-12')).toBe('12 Sep 2026');
        expect(formatDate('2026-01-01T00:30:00+06:00')).toBe('1 Jan 2026');
        expect(formatDate(null)).toBe('');
    });
});

describe('filters', () => {
    it('matches text, money comparisons and ranges, and status words', () => {
        expect(matchesFilter('Rahima Akter', 'akter', 'text')).toBe(true);
        expect(matchesFilter('45,000.00', '>40000', 'money')).toBe(true);
        expect(matchesFilter('45,000.00', '<=45,000', 'money')).toBe(true);
        expect(matchesFilter('45,000.00', '<45000', 'money')).toBe(false);
        expect(matchesFilter('45,000.00', '40000..50000', 'money')).toBe(true);
        expect(matchesFilter('(500.00)', '<0', 'money')).toBe(true);
        expect(matchesFilter('45,000.00', '45000', 'money')).toBe(true);
        expect(matchesFilter('2026-09-12', 'sep 2026', 'date')).toBe(true);
        expect(matchesFilter('pending_approval', 'pending approval', 'status')).toBe(true);
        expect(matchesFilter('posted', '', 'status')).toBe(true);
    });

    it('round-trips filters and multi-sort through the URL', () => {
        const params = encodeTableState({ filters: { amount: '>1000', customer: 'rahima' }, sort: [{ id: 'date', desc: true }, { id: 'amount', desc: false }] });
        expect(params.get('sort')).toBe('-date,amount');
        expect(decodeTableState(params)).toEqual({ filters: { amount: '>1000', customer: 'rahima' }, sort: [{ id: 'date', desc: true }, { id: 'amount', desc: false }] });
        expect(decodeTableState(new URLSearchParams('page=2'))).toEqual({ filters: {}, sort: [] });
    });
});
