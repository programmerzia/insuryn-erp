import { describe, expect, it } from 'vitest';
import { emptyDraft, formatAmount, formatHundredths, formatRate, formatWhole, parseAmount, parseHundredths, parseRate, parseWhole, rateWithUnit, rowDraft, rowPayload } from '@/lib/rating';

/** Slice R10a tariff editor units (D-20): rates and amounts typed as people read them, stored as integers, converted on the digits — never a float. */
describe('rate units', () => {
    it('reads a per mille rate into hundredths and back', () => {
        expect(parseRate('rate_pm', '2.25‰')).toBe(225);
        expect(parseRate('rate_pm', '2.25')).toBe(225);
        expect(parseRate('rate_pm', ' 0.8 ')).toBe(80);
        expect(formatRate('rate_pm', { value_bp: 225, value_minor: null })).toBe('2.25');
        expect(formatHundredths(80)).toBe('0.80');
        expect(formatHundredths(5)).toBe('0.05');
    });

    it('reads a percentage into basis points and back', () => {
        expect(parseRate('rate_pct', '10%')).toBe(1000);
        expect(parseRate('rate_pct', '12.5')).toBe(1250);
        expect(parseRate('rate_pct', '0.01')).toBe(1);
        expect(formatRate('rate_pct', { value_bp: 1000, value_minor: null })).toBe('10.00');
        expect(rateWithUnit('rate_pct', { value_bp: 1500, value_minor: null }, 'BDT')).toBe('15.00 %');
        expect(rateWithUnit('rate_pm', { value_bp: 2750, value_minor: null }, 'BDT')).toBe('27.50 ‰');
    });

    it('reads an amount in taka into minor units and back', () => {
        expect(parseRate('flat', '1,234.56')).toBe(123456);
        expect(parseAmount('2500')).toBe(250000);
        expect(formatAmount(250000)).toBe('2,500.00');
        expect(rateWithUnit('flat', { value_bp: null, value_minor: 4500 }, 'BDT')).toBe('45.00 BDT');
        expect(formatHundredths(123456)).toBe('1,234.56');
    });

    it('refuses what is not a value in the unit instead of rounding it', () => {
        for (const text of ['2.255', '-1', 'abc', '1.2.3', '', '%', '10%%', '1e3']) {
            expect(parseRate('rate_pct', text), text).toBeNull();
        }
        expect(parseRate('rate_pm', '2.25%')).toBeNull();
        expect(parseRate('flat', '12.345')).toBeNull();
        expect(parseRate('flat', '-5')).toBeNull();
        expect(parseHundredths('99999999999999', '%')).toBeNull();
    });

    it('keeps exact integers where floats would drift', () => {
        expect(parseRate('rate_pct', '0.29')).toBe(29);
        expect(parseRate('rate_pm', '1.15')).toBe(115);
        expect(parseRate('flat', '0.07')).toBe(7);
        expect(parseRate('flat', '90,071,992,547,409.91')).toBe(Number.MAX_SAFE_INTEGER);
        expect(parseRate('flat', '90,071,992,547,409.92')).toBeNull();
    });

    it('reads whole numbers for band bounds', () => {
        expect(parseWhole('1,301')).toBe(1301);
        expect(parseWhole('-5')).toBeNull();
        expect(parseWhole('-5', true)).toBe(-5);
        expect(parseWhole('1.5')).toBeNull();
        expect(formatWhole(1000000)).toBe('1,000,000');
        expect(formatWhole(null)).toBe('');
    });
});

describe('grid rows', () => {
    it('turns an edited rate row into the stored row, with its dates', () => {
        const draft = { ...emptyDraft(['vehicle_type', 'cc_band']), keys: { vehicle_type: 'private', cc_band: 'upto_1300' }, value: '22.50', effective_from: '2027-01-01' };
        expect(rowPayload('rate_pm', ['vehicle_type', 'cc_band'], draft)).toEqual({
            row: { keys: { vehicle_type: 'private', cc_band: 'upto_1300' }, value_bp: 2250, value_minor: null, band_from: null, band_to: null, band_label: null, effective_from: '2027-01-01', effective_to: null },
            errors: {},
        });
    });

    it('names every cell that cannot be read', () => {
        const draft = { ...emptyDraft(['occupancy']), value: '2.555', effective_from: '2027-01-01', effective_to: '2026-01-01' };
        expect(Object.keys(rowPayload('rate_pm', ['occupancy'], draft).errors).sort()).toEqual(['effective_to', 'keys.occupancy', 'value']);
        const band = { ...emptyDraft([]), band_from: '10', band_to: '5', value: 'ten' };
        expect(Object.keys(rowPayload('band', [], band).errors).sort()).toEqual(['band_label', 'band_to', 'value']);
    });

    it('round-trips stored rows through the grid unchanged', () => {
        const flat = { keys: { vehicle_type: 'private' }, value_bp: null, value_minor: 250000, band_from: null, band_to: null, band_label: null, effective_from: null, effective_to: '2027-01-01' };
        expect(rowPayload('flat', ['vehicle_type'], rowDraft('flat', flat)).row).toEqual(flat);
        const band = { keys: {}, value_bp: 1000, value_minor: null, band_from: 1, band_to: 2, band_label: 'ncb_10', effective_from: null, effective_to: null };
        expect(rowPayload('band', [], rowDraft('band', band)).row).toEqual(band);
        const open = { keys: {}, value_bp: null, value_minor: 5000, band_from: 1801, band_to: null, band_label: 'above_1800', effective_from: null, effective_to: null };
        expect(rowPayload('band', [], rowDraft('band', open)).row).toEqual(open);
    });
});
