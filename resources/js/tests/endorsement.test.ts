import { describe, expect, it } from 'vitest';
import { basisSentence, changeRows, changeSentence, type EndorsementRatingData, premiumParts } from '@/lib/endorsement';
import type { RatingResultData } from '@/lib/riskForm';

const result = (net: number, vat: number, stamp: number, version = 1): RatingResultData => ({
    currency: 'BDT', as_of: '2026-09-15', plan: { id: 'p', code: 'MOTOR-TARIFF', version, class_code: 'motor' }, net_premium_minor: net,
    duties: [{ code: 'stamp', label_en: 'Stamp duty', label_bn: 'স্ট্যাম্প শুল্ক', amount_minor: stamp }, { code: 'vat', label_en: 'VAT 15%', label_bn: 'মূসক ১৫%', amount_minor: vat }],
    duties_total_minor: vat + stamp, gross_premium_minor: net + vat + stamp, explanation: [], risk_inputs: {}, coverages: [], verify: true,
});

// The golden motor policy (net 26,842.00, VAT 4,026.30, stamp 50.00) endorsed when the driver turns 30 (net 24,402.00, VAT 3,660.30).
const rating: EndorsementRatingData = {
    basis: 'original_plan', before: result(2_684_200, 402_630, 5_000), after: result(2_440_200, 366_030, 5_000),
    change: { net_minor: -244_000, tax_minor: -36_600, stamp_duty_minor: 0, gross_minor: -280_600 }, pro_rata: false, days_charged: 335, days_in_term: 365,
};

describe('endorsement re-rating on the policy page (slice R7)', () => {
    it('splits a rating result into net, VAT and levies, stamp duty and gross', () => {
        expect(premiumParts(rating.before)).toEqual({ net: 2_684_200, tax: 402_630, stamp: 5_000, gross: 3_091_830 });
    });

    it('shows before, after and the change charged per part, decreases in parentheses', () => {
        expect(changeRows(rating)).toEqual([
            { label: 'Net premium', before: '26,842.00', after: '24,402.00', change: '(2,440.00)' },
            { label: 'VAT and levies', before: '4,026.30', after: '3,660.30', change: '(366.00)' },
            { label: 'Stamp duty', before: '50.00', after: '50.00', change: '0.00' },
            { label: 'Gross premium', before: '30,918.30', after: '28,112.30', change: '(2,806.00)' },
        ]);
    });

    it('says which tariff re-rated the risk, how the change is charged and what it does', () => {
        expect(basisSentence(rating)).toBe("Re-rated on the policy's own tariff, MOTOR-TARIFF version 1. The whole annual difference is charged.");
        expect(basisSentence({ ...rating, basis: 'current_tariff', after: result(2_447_400, 367_110, 5_000, 2), pro_rata: true }))
            .toBe('Re-rated on the tariff in force, MOTOR-TARIFF version 2. Net premium and VAT are charged for 335 of 365 days; stamp duty in full.');
        expect(changeSentence(rating, 'BDT')).toBe('The premium goes down by BDT 2,806.00.');
        expect(changeSentence({ ...rating, change: { net_minor: 8_000, tax_minor: 1_200, stamp_duty_minor: 0, gross_minor: 9_200 } }, 'BDT')).toBe('The premium goes up by BDT 92.00.');
        expect(changeSentence({ ...rating, change: { net_minor: 0, tax_minor: 0, stamp_duty_minor: 0, gross_minor: 0 } }, 'BDT')).toBe('No premium change: nothing is posted.');
    });
});
