import { describe, expect, it } from 'vitest';
import { boundsHint, breakdown, formFields, initialValues, localProblems, problemMessage, ratingKey, riskInputs, type RiskFieldDefinition, versionOn } from '@/lib/riskForm';

const schema: RiskFieldDefinition[] = [
    { key: 'vehicle_type', label_en: 'Vehicle type', label_bn: 'যানবাহনের ধরন', type: 'select', required: true, options: [{ value: 'private', label_en: 'Private car', label_bn: 'ব্যক্তিগত গাড়ি' }] },
    { key: 'registration_no', label_en: 'Registration number', label_bn: 'নিবন্ধন নম্বর', type: 'text', required: true, max_length: 8 },
    { key: 'engine_cc', label_en: 'Engine capacity (cc)', label_bn: 'ইঞ্জিন ক্ষমতা (সিসি)', type: 'integer', required: true, min: 50, max: 10000 },
    { key: 'year_of_manufacture', label_en: 'Year of manufacture', label_bn: 'তৈরির বছর', type: 'integer', required: true, min: 1950, max: 2100 },
    { key: 'sum_insured', label_en: 'Sum insured', label_bn: 'বিমাকৃত অঙ্ক', type: 'money', required: true, min: 1 },
    { key: 'first_registered', label_en: 'First registered', label_bn: 'প্রথম নিবন্ধন', type: 'date', required: false },
    { key: 'garaged', label_en: 'Garaged at night', label_bn: 'রাতে গ্যারেজে', type: 'boolean', required: false },
    { key: 'ncb_years', label_en: 'Claim-free years', label_bn: 'দাবিমুক্ত বছর', type: 'integer', required: false, max: 50 },
];

describe('risk form generated from the risk schema (slice R4)', () => {
    it('builds one field per schema entry with labels in the user language, options, required marks and bounds', () => {
        const english = formFields(schema, 'en');
        expect(english.map((f) => [f.key, f.type, f.required])).toEqual(schema.map((f) => [f.key, f.type, f.required]));
        expect(english[0]).toEqual({ key: 'vehicle_type', label: 'Vehicle type', type: 'select', required: true, options: [{ value: 'private', label: 'Private car' }], hint: null, maxLength: null });
        expect(english[2]?.hint).toBe('50 to 10,000');
        expect(english[3]?.hint).toBe('1950 to 2100');
        expect(english[4]?.hint).toBe('At least 0.01');
        expect(english[7]?.hint).toBe('Up to 50');
        expect(english[1]?.maxLength).toBe(8);
        const bangla = formFields(schema, 'bn');
        expect(bangla[0]?.label).toBe('যানবাহনের ধরন');
        expect(bangla[0]?.options[0]?.label).toBe('ব্যক্তিগত গাড়ি');
        expect(boundsHint(schema[1]!)).toBeNull();
    });

    it('turns typed values into risk inputs without floats: whole numbers, money in minor units as digits, empty fields left out', () => {
        const values = { vehicle_type: 'private', registration_no: ' DHA-1 ', engine_cc: '1,500', year_of_manufacture: '2020', sum_insured: '1,234,567.00', first_registered: '', garaged: true, ncb_years: '' };
        expect(riskInputs(schema, values)).toEqual({ vehicle_type: 'private', registration_no: 'DHA-1', engine_cc: 1500, year_of_manufacture: 2020, sum_insured: '123456700', garaged: true });
        expect(riskInputs(schema, { ...values, sum_insured: '99999999999999999.99' }).sum_insured).toBe('9999999999999999999');
        expect(riskInputs(schema, { ...values, engine_cc: '1500.5', sum_insured: 'lots' })).toMatchObject({ engine_cc: '1500.5', sum_insured: 'lots' });
    });

    it('fills the form back from saved inputs, money in major units', () => {
        expect(initialValues(schema, { vehicle_type: 'private', engine_cc: 1500, sum_insured: 123456700, garaged: true, registration_no: null })).toEqual({
            vehicle_type: 'private', registration_no: '', engine_cc: '1500', year_of_manufacture: '', sum_insured: '1,234,567.00', first_registered: '', garaged: true, ncb_years: '',
        });
        expect(initialValues(schema, null).garaged).toBe(false);
    });

    it('finds the problems before rating and words the server problem codes', () => {
        const problems = localProblems(schema, { vehicle_type: 'tractor', registration_no: 'TOO-LONG-NUMBER', engine_cc: '20', year_of_manufacture: '', sum_insured: '0.00', first_registered: '2026-02-30x', garaged: false, ncb_years: '51' });
        expect(problems).toEqual({
            vehicle_type: 'Choose one of the options.', registration_no: 'Keep it to 8 characters.', engine_cc: 'Enter at least 50.', year_of_manufacture: 'Enter the year of manufacture.',
            sum_insured: 'Enter at least 0.01.', first_registered: 'Enter a date like 15 Sep 2026.', ncb_years: 'Enter at most 50.',
        });
        expect(localProblems(schema, { vehicle_type: '', registration_no: 'D', engine_cc: '1500', year_of_manufacture: '2020', sum_insured: '10.00', garaged: false })).toEqual({ vehicle_type: 'Choose the vehicle type.' });
        expect(problemMessage('NOT_INTEGER', schema[4])).toBe('Enter an amount, like 1,234,567.00.');
        expect(problemMessage('UNKNOWN_FIELD', undefined)).toBe('This product does not ask for this.');
    });

    it('picks the product version in force on the cover start', () => {
        const base = { class_code: 'motor', risk_schema: [], coverages: [] };
        const versions = [{ ...base, id: 'v1', version: 1, effective_from: '2026-01-01', effective_to: '2026-10-01' }, { ...base, id: 'v2', version: 2, effective_from: '2026-10-01', effective_to: null }];
        expect(versionOn(versions, '2026-09-30')?.id).toBe('v1');
        expect(versionOn(versions, '2026-10-01')?.id).toBe('v2');
        expect(versionOn(versions, '2025-12-31')).toBeNull();
    });

    it('shows the breakdown in English or Bangla, and rates again only when inputs change', () => {
        const result = {
            currency: 'BDT', as_of: '2026-09-15', plan: { id: 'p', code: 'MOTOR-TARIFF', version: 1, class_code: 'motor' }, net_premium_minor: 2684200, duties_total_minor: 407630, gross_premium_minor: 3091830,
            duties: [], risk_inputs: {}, coverages: [], verify: true,
            explanation: [
                { step_code: 'own_damage', kind: 'base', label_en: 'Own damage premium', label_bn: 'নিজস্ব ক্ষতির প্রিমিয়াম', amount_minor: 2777776, running_total_minor: 2777776 },
                { step_code: 'no_claim_bonus', kind: 'discount', label_en: 'No-claim bonus', label_bn: 'দাবিহীন বোনাস', amount_minor: -671061, running_total_minor: 2106715 },
            ],
        };
        expect(breakdown(result, 'en')).toEqual([
            { code: 'own_damage', kind: 'base', label: 'Own damage premium', amount: '27,777.76', running: '27,777.76' },
            { code: 'no_claim_bonus', kind: 'discount', label: 'No-claim bonus', amount: '(6,710.61)', running: '21,067.15' },
        ]);
        expect(breakdown(result, 'bn')[1]?.label).toBe('দাবিহীন বোনাস');
        const key = ratingKey({ product_id: 'p1', inception: '2026-09-15', risk_inputs: { b: 1, a: 'x' }, coverages: ['z', 'y'] });
        expect(ratingKey({ product_id: 'p1', inception: '2026-09-15', risk_inputs: { a: 'x', b: 1 }, coverages: ['y', 'z'] })).toBe(key);
        expect(ratingKey({ product_id: 'p1', inception: '2026-09-16', risk_inputs: { a: 'x', b: 1 }, coverages: ['y', 'z'] })).not.toBe(key);
    });
});
