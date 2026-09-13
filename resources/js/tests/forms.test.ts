import { describe, expect, it } from 'vitest';
import { parseDateInput } from '@/lib/dates';
import { normaliseMoneyInput, stepMoney } from '@/lib/money';

const today = new Date(2026, 8, 13); // 13 Sep 2026

describe('keyboard date entry', () => {
    it('understands t, +n and -n relative to today', () => {
        expect(parseDateInput('t', today)).toBe('2026-09-13');
        expect(parseDateInput('+3', today)).toBe('2026-09-16');
        expect(parseDateInput('-13', today)).toBe('2026-08-31');
        expect(parseDateInput('+30', today)).toBe('2026-10-13');
    });

    it('reads typed dates in the forms people write them', () => {
        expect(parseDateInput('12 Sep 2026', today)).toBe('2026-09-12');
        expect(parseDateInput('12 sep', today)).toBe('2026-09-12');
        expect(parseDateInput('2026-09-12', today)).toBe('2026-09-12');
        expect(parseDateInput('12/09/2026', today)).toBe('2026-09-12');
        expect(parseDateInput('1.1.27', today)).toBe('2027-01-01');
        expect(parseDateInput('31 feb 2026', today)).toBeNull();
        expect(parseDateInput('soon', today)).toBeNull();
        expect(parseDateInput('', today)).toBeNull();
    });
});

describe('money input', () => {
    it('steps by 1,000 with the arrow keys and never goes below zero unless negatives are allowed', () => {
        expect(stepMoney('50,000.00', 1000n * 100n)).toBe('51,000.00');
        expect(stepMoney('', 1000n * 100n)).toBe('1,000.00');
        expect(stepMoney('500.00', -1000n * 100n)).toBe('0.00');
        expect(stepMoney('500.00', -1000n * 100n, true)).toBe('-500.00');
    });

    it('formats on blur and keeps what cannot be read so the error can explain it', () => {
        expect(normaliseMoneyInput('50000')).toBe('50,000.00');
        expect(normaliseMoneyInput('1234.5')).toBe('1,234.50');
        expect(normaliseMoneyInput('-250', true)).toBe('-250.00');
        expect(normaliseMoneyInput('12,34x')).toBe('12,34x');
        expect(normaliseMoneyInput('')).toBe('');
    });
});
