<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

/**
 * The variable bag every document template receives (slice R8), its documentation per template code, and demo values for the preview.
 *
 * Every template gets the same top-level variables, so a body written for one document works for another; a data provider fills what its document
 * has and leaves the rest empty. All values are text (money and dates already formatted), lists of rows, or nested text — never objects — and
 * every value is escaped when printed.
 */
final class DocumentVariables
{
    /** Top-level variables, in documentation order. */
    public const NAMES = ['company', 'document', 'currency', 'parties', 'product', 'period', 'details', 'money', 'total', 'special_terms', 'installments', 'allocations', 'rating'];

    /**
     * An empty bag with every variable present, so a template never meets an undefined variable.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return ['company' => ['name' => ''], 'document' => ['title' => '', 'number' => '', 'date' => ''], 'currency' => '', 'parties' => [],
            'product' => ['code' => '', 'name' => '', 'class' => ''], 'period' => ['from' => '', 'to' => ''], 'details' => [], 'money' => [],
            'total' => ['label' => '', 'amount' => ''], 'special_terms' => [], 'installments' => [], 'allocations' => [], 'rating' => []];
    }

    /**
     * A provider's variables laid over the blank bag: unknown keys are dropped and every leaf becomes text.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public static function normalise(array $variables): array
    {
        $bag = self::blank();
        foreach (self::NAMES as $name) {
            if (! array_key_exists($name, $variables)) {
                continue;
            }
            $bag[$name] = is_array($bag[$name]) && is_array($variables[$name])
                ? (array_is_list($bag[$name]) ? array_values(array_map(self::text(...), $variables[$name])) : array_replace($bag[$name], self::text($variables[$name])))
                : self::text($variables[$name]);
        }

        return $bag;
    }

    /**
     * What each variable holds for a template code (the editor's variables list).
     *
     * @return list<array{name: string, description: string}>
     */
    public static function documentation(DocumentTemplateCode $code): array
    {
        $specific = match ($code) {
            DocumentTemplateCode::Quotation => ['details' => 'Risk details as rows {label, value}, then "Valid until"', 'money' => 'Premium breakdown rows {label, amount}: net premium, each duty',
                'total' => 'Gross premium {label, amount}', 'period' => 'Proposed period of cover {from, to}', 'rating' => 'Rating explanation rows {label, amount}'],
            DocumentTemplateCode::CoverNote => ['details' => 'Risk details as rows {label, value}; the cover note\'s validity', 'money' => 'Premium rows {label, amount}',
                'total' => 'Gross premium {label, amount}', 'period' => 'Temporary cover {from, to}'],
            DocumentTemplateCode::PolicySchedule => ['details' => 'Policy details as rows {label, value}: status, channel, issue date, installments', 'money' => 'Premium rows {label, amount}: net premium, VAT and duties',
                'total' => 'Gross premium {label, amount}', 'period' => 'Period of insurance {from, to}', 'special_terms' => 'Special terms (endorsement reasons, manual rating adjustments) as text lines',
                'installments' => 'Installment rows {no, due_date, amount}', 'rating' => 'Rating breakdown rows {label, amount} when the policy was rated'],
            DocumentTemplateCode::Endorsement => ['details' => 'Endorsement details as rows {label, value}: policy number, effective date, reason', 'money' => 'Premium change rows {label, amount}',
                'total' => 'Total premium change {label, amount}', 'period' => 'Period of insurance {from, to}', 'special_terms' => 'The endorsement wording as text lines'],
            DocumentTemplateCode::Receipt => ['details' => 'Receipt details as rows {label, value}: received on, method, reference', 'allocations' => 'What the money paid, rows {reference, description, amount}',
                'total' => 'Amount received {label, amount}'],
            DocumentTemplateCode::RenewalNotice => ['details' => 'Renewal details as rows {label, value}: expiring policy, expiry date, renew by', 'money' => 'Renewal premium rows {label, amount}',
                'total' => 'Renewal premium {label, amount}', 'period' => 'Proposed renewal period {from, to}'],
            DocumentTemplateCode::ClaimAck => ['details' => 'Claim details as rows {label, value}: policy, date of loss, reported on, description'],
            DocumentTemplateCode::DischargeVoucher => ['details' => 'Claim and payment details as rows {label, value}', 'money' => 'Settlement rows {label, amount}',
                'total' => 'Amount paid {label, amount}'],
        };
        $common = [
            'company' => 'The insurer: {name}', 'document' => 'This document: {title, number, date}', 'currency' => 'Currency code of the amounts, like BDT',
            'parties' => 'People and companies on the document, rows {role, name}', 'product' => 'The product: {code, name, class}', 'period' => 'Dates {from, to}',
            'details' => 'Rows {label, value}', 'money' => 'Rows {label, amount} (amounts are formatted text)', 'total' => '{label, amount}',
            'special_terms' => 'Text lines', 'installments' => 'Rows {no, due_date, amount}', 'allocations' => 'Rows {reference, description, amount}', 'rating' => 'Rows {label, amount}',
        ];

        return array_map(fn (string $name): array => ['name' => $name, 'description' => $specific[$name] ?? $common[$name]], self::NAMES);
    }

    /**
     * Demo values for the preview of a template code (illustrative, not a real customer).
     *
     * @return array<string, mixed>
     */
    public static function demo(DocumentTemplateCode $code, string $locale): array
    {
        $bn = $locale === 'bn';
        $l = fn (string $en, string $bangla): string => $bn ? $bangla : $en;
        $bag = array_replace(self::blank(), [
            'company' => ['name' => $l('Padma General Insurance PLC', 'পদ্মা জেনারেল ইন্স্যুরেন্স পিএলসি')],
            'document' => ['title' => $code->title($locale), 'number' => self::demoNumber($code), 'date' => '14 Sep 2026'],
            'currency' => 'BDT',
            'parties' => [['role' => $l('Policyholder', 'পলিসিগ্রহীতা'), 'name' => $l('Rahima Akter', 'রহিমা আক্তার')], ['role' => $l('Agent', 'এজেন্ট'), 'name' => $l('Jamal Uddin (AG-001)', 'জামাল উদ্দিন (AG-001)')]],
            'product' => ['code' => 'MOTOR', 'name' => $l('Motor Comprehensive', 'মোটর কম্প্রিহেনসিভ'), 'class' => $l('Motor', 'মোটর')],
            'period' => ['from' => '14 Sep 2026', 'to' => '13 Sep 2027'],
            'money' => [['label' => $l('Net premium', 'নিট প্রিমিয়াম'), 'amount' => '26,885.48'], ['label' => $l('Stamp duty', 'স্ট্যাম্প ডিউটি'), 'amount' => '50.00'],
                ['label' => $l('VAT', 'মূসক'), 'amount' => '3,982.82']],
            'total' => ['label' => $l('Gross premium', 'মোট প্রিমিয়াম'), 'amount' => '30,918.30'],
        ]);
        $risk = [['label' => $l('Vehicle', 'যানবাহন'), 'value' => $l('Private car, 1,500 cc, 5 seats', 'প্রাইভেট কার, ১৫০০ সিসি, ৫ আসন')],
            ['label' => $l('Registration', 'নিবন্ধন নম্বর'), 'value' => 'DHAKA METRO-GA 12-3456'], ['label' => $l('Sum insured', 'বীমাকৃত অর্থ'), 'value' => 'BDT 1,200,000.00']];

        return match ($code) {
            DocumentTemplateCode::Quotation => array_replace($bag, ['details' => [...$risk, ['label' => $l('Valid until', 'বৈধতার শেষ তারিখ'), 'value' => '29 Sep 2026']],
                'rating' => [['label' => $l('Own damage', 'নিজস্ব ক্ষতি'), 'amount' => '27,000.00'], ['label' => $l('No-claim bonus', 'দাবিহীন বোনাস'), 'amount' => '(2,700.00)']]]),
            DocumentTemplateCode::CoverNote => array_replace($bag, ['details' => [...$risk, ['label' => $l('Valid for', 'মেয়াদ'), 'value' => $l('30 days', '৩০ দিন')]],
                'period' => ['from' => '14 Sep 2026', 'to' => '13 Oct 2026']]),
            DocumentTemplateCode::PolicySchedule => array_replace($bag, [
                'details' => [...$risk, ['label' => $l('Issued on', 'ইস্যুর তারিখ'), 'value' => '14 Sep 2026'], ['label' => $l('Installments', 'কিস্তি'), 'value' => '2']],
                'special_terms' => [$l('Young driver loading of 10% applies while the named driver is under 25.', 'নাম উল্লিখিত চালকের বয়স ২৫ বছরের কম থাকা পর্যন্ত ১০% অতিরিক্ত প্রিমিয়াম প্রযোজ্য।')],
                'installments' => [['no' => '1', 'due_date' => '14 Sep 2026', 'amount' => '15,459.15'], ['no' => '2', 'due_date' => '14 Mar 2027', 'amount' => '15,459.15']],
                'rating' => [['label' => $l('Own damage', 'নিজস্ব ক্ষতি'), 'amount' => '27,000.00'], ['label' => $l('Third party liability', 'তৃতীয় পক্ষের দায়'), 'amount' => '2,500.00']],
            ]),
            DocumentTemplateCode::Endorsement => array_replace($bag, [
                'details' => [['label' => $l('Policy', 'পলিসি'), 'value' => 'POL-HO-2026-000123'], ['label' => $l('Effective from', 'কার্যকর তারিখ'), 'value' => '1 Oct 2026'],
                    ['label' => $l('Reason', 'কারণ'), 'value' => $l('Sum insured increased', 'বীমাকৃত অর্থ বৃদ্ধি')]],
                'money' => [['label' => $l('Net premium change', 'নিট প্রিমিয়াম পরিবর্তন'), 'amount' => '1,739.13'], ['label' => $l('VAT change', 'মূসক পরিবর্তন'), 'amount' => '260.87']],
                'total' => ['label' => $l('Total premium change', 'মোট প্রিমিয়াম পরিবর্তন'), 'amount' => '2,000.00'],
                'special_terms' => [$l('The sum insured is increased to BDT 1,400,000.00 from 1 Oct 2026. All other terms remain unchanged.', '১ অক্টোবর ২০২৬ থেকে বীমাকৃত অর্থ ১৪,০০,০০০.০০ টাকায় উন্নীত করা হলো। অন্যান্য সকল শর্ত অপরিবর্তিত থাকবে।')],
            ]),
            DocumentTemplateCode::Receipt => array_replace($bag, [
                'parties' => [['role' => $l('Received from', 'প্রদানকারী'), 'name' => $l('Rahima Akter', 'রহিমা আক্তার')]], 'period' => ['from' => '', 'to' => ''], 'money' => [], 'product' => ['code' => '', 'name' => '', 'class' => ''],
                'details' => [['label' => $l('Received on', 'প্রাপ্তির তারিখ'), 'value' => '14 Sep 2026'], ['label' => $l('Method', 'পরিশোধের মাধ্যম'), 'value' => $l('Bank transfer', 'ব্যাংক ট্রান্সফার')],
                    ['label' => $l('Reference', 'রেফারেন্স'), 'value' => 'TT 88231']],
                'allocations' => [['reference' => 'POL-HO-2026-000123', 'description' => $l('Installment 1', 'কিস্তি ১'), 'amount' => '12,000.00']],
                'total' => ['label' => $l('Amount received', 'প্রাপ্ত অর্থ'), 'amount' => '12,000.00'],
            ]),
            DocumentTemplateCode::RenewalNotice => array_replace($bag, [
                'details' => [['label' => $l('Expiring policy', 'মেয়াদোত্তীর্ণ পলিসি'), 'value' => 'POL-HO-2026-000123'], ['label' => $l('Expires on', 'মেয়াদ শেষ'), 'value' => '13 Sep 2027'],
                    ['label' => $l('Renew by', 'নবায়নের শেষ তারিখ'), 'value' => '13 Sep 2027']],
                'period' => ['from' => '14 Sep 2027', 'to' => '13 Sep 2028'],
                'total' => ['label' => $l('Renewal premium', 'নবায়ন প্রিমিয়াম'), 'amount' => '28,120.00'],
            ]),
            DocumentTemplateCode::ClaimAck => array_replace($bag, ['money' => [], 'total' => ['label' => '', 'amount' => ''],
                'details' => [['label' => $l('Policy', 'পলিসি'), 'value' => 'POL-HO-2026-000123'], ['label' => $l('Date of loss', 'ক্ষতির তারিখ'), 'value' => '5 Sep 2026'],
                    ['label' => $l('Reported on', 'অবহিত করার তারিখ'), 'value' => '6 Sep 2026'], ['label' => $l('Description', 'বিবরণ'), 'value' => $l('Rear collision', 'পেছন থেকে সংঘর্ষ')]],
            ]),
            DocumentTemplateCode::DischargeVoucher => array_replace($bag, [
                'parties' => [['role' => $l('Claimant', 'দাবিদার'), 'name' => $l('Rahima Akter', 'রহিমা আক্তার')]],
                'details' => [['label' => $l('Claim', 'দাবি'), 'value' => 'CLM-2026-000003'], ['label' => $l('Policy', 'পলিসি'), 'value' => 'POL-HO-2026-000123'], ['label' => $l('Date of loss', 'ক্ষতির তারিখ'), 'value' => '5 Sep 2026']],
                'money' => [['label' => $l('Assessed loss', 'নিরূপিত ক্ষতি'), 'amount' => '185,000.00'], ['label' => $l('Deductible', 'ডিডাক্টিবল'), 'amount' => '(5,000.00)']],
                'total' => ['label' => $l('Amount paid in full and final settlement', 'পূর্ণ ও চূড়ান্ত নিষ্পত্তিতে প্রদত্ত অর্থ'), 'amount' => '180,000.00'],
            ]),
        };
    }

    private static function demoNumber(DocumentTemplateCode $code): string
    {
        return match ($code) {
            DocumentTemplateCode::Quotation => 'QUO-HO-2026-000045',
            DocumentTemplateCode::CoverNote => 'CN-HO-2026-000012',
            DocumentTemplateCode::PolicySchedule, DocumentTemplateCode::RenewalNotice => 'POL-HO-2026-000123',
            DocumentTemplateCode::Endorsement => 'POL-HO-2026-000123/E1',
            DocumentTemplateCode::Receipt => 'RCT-2026-000007',
            DocumentTemplateCode::ClaimAck, DocumentTemplateCode::DischargeVoucher => 'CLM-2026-000003',
        };
    }

    /** Text leaves: scalars become strings, lists and maps keep their shape, anything else is dropped. */
    private static function text(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::text(...), $value),
            is_string($value) => $value,
            is_int($value), is_bool($value) => (string) $value,
            default => '',
        };
    }
}
