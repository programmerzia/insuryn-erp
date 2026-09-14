import { afterEach, describe, expect, it } from 'vitest';
import { localProblems, problemMessage, type RiskFieldDefinition, serverProblems } from '@/lib/riskForm';
import { formMessages, installLocalizedValidity, nativeValidityMessage } from '@/lib/validationMessages';

/** Gap fixes W7 (L5): the browser-side checks of a form say what is wrong in the user's language. */
const schema: RiskFieldDefinition[] = [
    { key: 'vehicle_type', label_en: 'Vehicle type', label_bn: 'যানবাহনের ধরন', type: 'select', required: true, options: [{ value: 'private', label_en: 'Private car', label_bn: 'ব্যক্তিগত গাড়ি' }] },
    { key: 'registration_no', label_en: 'Registration number', label_bn: 'নিবন্ধন নম্বর', type: 'text', required: true, max_length: 8 },
    { key: 'engine_cc', label_en: 'Engine capacity (cc)', label_bn: 'ইঞ্জিন ক্ষমতা (সিসি)', type: 'integer', required: true, min: 50, max: 10000 },
    { key: 'sum_insured', label_en: 'Sum insured', label_bn: 'বিমাকৃত অঙ্ক', type: 'money', required: true, min: 1 },
];
const valid = { valueMissing: false, typeMismatch: false, patternMismatch: false, tooLong: false, tooShort: false, rangeUnderflow: false, rangeOverflow: false, stepMismatch: false, badInput: false };

let uninstall: (() => void) | null = null;
afterEach(() => {
    uninstall?.();
    uninstall = null;
    document.body.innerHTML = '';
});

describe('risk form problems in the user language', () => {
    it('words the browser checks in Bangla like the server does (RiskProblems, A-161), and in English as before', () => {
        const values = { vehicle_type: '', registration_no: 'DHA-METRO-GA-1', engine_cc: '20', sum_insured: '' };
        expect(localProblems(schema, values, 'quote', 'bn')).toEqual({
            vehicle_type: 'যানবাহনের ধরন বেছে নিন।',
            registration_no: '8 অক্ষরের মধ্যে রাখুন।',
            engine_cc: 'কমপক্ষে 50 লিখুন।',
            sum_insured: 'বিমাকৃত অঙ্ক লিখুন।',
        });
        expect(localProblems(schema, values)).toEqual({
            vehicle_type: 'Choose the vehicle type.',
            registration_no: 'Keep it to 8 characters.',
            engine_cc: 'Enter at least 50.',
            sum_insured: 'Enter the sum insured.',
        });
        expect(problemMessage('NOT_INTEGER', schema[3], 'bn')).toBe('টাকার অঙ্ক লিখুন, যেমন 1,234,567.00।');
        expect(problemMessage('SOMETHING_ELSE', undefined, 'bn')).toBe('মানটি যাচাই করুন।');
        // A code the server sent without a sentence falls back to the browser's wording in the same language.
        expect(serverProblems({ errors: { engine_cc: 'ABOVE_MAX' } }, schema, 'bn')).toEqual({ engine_cc: 'সর্বোচ্চ 10,000 লিখুন।' });
    });

    it('gives the claim and policy forms their messages in Bangla', () => {
        expect(formMessages.enterLossDate('bn')).toBe('ক্ষতির তারিখ লিখুন।');
        expect(formMessages.enterLossDate('en')).toBe('Enter the date of loss.');
        expect(formMessages.lossOutsideCover('bn', '1 Sep 2026', '31 Aug 2027')).toBe('ক্ষতির তারিখ কভারের মধ্যে হতে হবে, 1 Sep 2026 থেকে 31 Aug 2027।');
        expect(formMessages.enterGrossPremium('en', 'BDT')).toBe('Enter the gross premium in BDT, like 120,000.00.');
        expect(formMessages.payerShares('bn', '90.00')).toBe('প্রিমিয়াম প্রদানকারীদের অংশ মিলে 90.00%; মোট 100% হতে হবে।');
    });
});

describe('browser constraint messages', () => {
    it('words each constraint in Bangla and leaves the browser wording in English', () => {
        expect(nativeValidityMessage({ validity: { ...valid, valueMissing: true } }, 'bn')).toBe('এই ঘরটি পূরণ করুন।');
        expect(nativeValidityMessage({ validity: { ...valid, typeMismatch: true }, type: 'email' }, 'bn')).toBe('একটি পূর্ণ ইমেইল ঠিকানা লিখুন, @ ও তার পরের অংশসহ।');
        expect(nativeValidityMessage({ validity: { ...valid, rangeUnderflow: true }, min: '1' }, 'bn')).toBe('কমপক্ষে 1 লিখুন।');
        expect(nativeValidityMessage({ validity: { ...valid, rangeOverflow: true }, max: '99' }, 'bn')).toBe('সর্বোচ্চ 99 লিখুন।');
        expect(nativeValidityMessage({ validity: { ...valid, tooLong: true }, maxLength: 8 }, 'bn')).toBe('8 অক্ষরের মধ্যে রাখুন।');
        expect(nativeValidityMessage({ validity: valid }, 'bn')).toBe('');
        expect(nativeValidityMessage({ validity: { ...valid, valueMissing: true } }, 'en')).toBe('');
    });

    it('sets the Bangla message when the browser finds a field invalid, and clears it when the user edits the field', () => {
        let locale: 'en' | 'bn' = 'bn';
        uninstall = installLocalizedValidity(() => locale);
        document.body.innerHTML = '<form><input id="email" type="email" required></form>';
        const input = document.getElementById('email') as HTMLInputElement;

        input.checkValidity();
        expect(input.validationMessage).toBe('এই ঘরটি পূরণ করুন।');

        input.value = 'rahima@example.com';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        expect(input.checkValidity()).toBe(true);

        locale = 'en';
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.checkValidity();
        expect(input.validationMessage).not.toBe('এই ঘরটি পূরণ করুন।');
    });
});
