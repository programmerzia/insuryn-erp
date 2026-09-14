import { describe, expect, it } from 'vitest';
import { eventLabel, isEventType } from '@/lib/events';
import { reportColumnWidth } from '@/lib/reportColumns';

/** Gap audit GA-34 and GA-06: labels only for event codes, report columns sized to fit their headers and values. */
describe('report cells and columns (GA-34, GA-06)', () => {
    it('turns only event types into words, so a branch code stays as it is', () => {
        expect(isEventType('POLICY_ISSUED')).toBe(true);
        expect(eventLabel('POLICY_ISSUED')).toBe('Policy issued');
        expect(isEventType('HO')).toBe(false);
        expect(isEventType('CTG')).toBe(false);
    });

    it('sizes a column to its header or its longest value within limits', () => {
        expect(reportColumnWidth('Earned premium (BDT)', ['1,234.00'], 'money')).toBeGreaterThanOrEqual(Math.ceil('Earned premium (BDT)'.length * 7.5) + 44);
        expect(reportColumnWidth('Branch', ['HO', 'CTG'], 'text')).toBeLessThan(120);
        expect(reportColumnWidth('Description', ['x'.repeat(200)], 'text')).toBe(280);
        expect(reportColumnWidth('Date', ['2026-09-01'], 'date')).toBe(110);
    });
});
