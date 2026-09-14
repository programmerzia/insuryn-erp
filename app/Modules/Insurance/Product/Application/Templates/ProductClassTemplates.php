<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Application\Templates;

/**
 * Product class templates: the risk schema and coverages of each MVP product class (slice R1), shared by the demo seeders (`Database\Seeders\DemoRatingCatalogue`)
 * and, since gap fix GA-18, the setup wizard's product step, which starts a new tenant's rated product from them. Illustrative, not a regulator's form:
 * review with underwriting before real use.
 */
final class ProductClassTemplates
{
    /** Template products the setup wizard offers: class code => [product code, name, line of business]. */
    public const PRODUCTS = [
        'motor' => ['MOTOR', 'Motor Comprehensive', 'motor'],
        'fire' => ['FIRE', 'Fire and Allied Perils', 'fire'],
        'marine_cargo' => ['MARINE', 'Marine Cargo', 'marine'],
    ];

    /**
     * The product version terms that give a demo product its class, risk schema and coverages (merge into ProductCatalogue::addVersion terms).
     *
     * @return array{class_code: string, risk_schema: list<array<string, mixed>>, coverage_definitions: list<array<string, mixed>>}
     */
    public static function versionTerms(string $classCode): array
    {
        return ['class_code' => $classCode, 'risk_schema' => self::riskSchema($classCode), 'coverage_definitions' => self::coverages($classCode)];
    }

    /** @return list<array<string, mixed>> */
    public static function riskSchema(string $classCode): array
    {
        $sumInsured = ['key' => 'sum_insured', 'label_en' => 'Sum insured', 'label_bn' => 'বিমাকৃত অঙ্ক', 'type' => 'money', 'required' => true, 'min' => 1];

        return match ($classCode) {
            'motor' => [
                ['key' => 'vehicle_type', 'label_en' => 'Vehicle type', 'label_bn' => 'যানবাহনের ধরন', 'type' => 'select', 'required' => true, 'default' => 'private', 'options' => [
                    ['value' => 'private', 'label_en' => 'Private car', 'label_bn' => 'ব্যক্তিগত গাড়ি'],
                    ['value' => 'commercial', 'label_en' => 'Commercial vehicle', 'label_bn' => 'বাণিজ্যিক যান'],
                    ['value' => 'motorcycle', 'label_en' => 'Motorcycle', 'label_bn' => 'মোটরসাইকেল'],
                ]],
                ['key' => 'registration_no', 'label_en' => 'Registration number', 'label_bn' => 'নিবন্ধন নম্বর', 'type' => 'text', 'required' => true, 'max_length' => 32],
                ['key' => 'chassis_no', 'label_en' => 'Chassis number', 'label_bn' => 'চেসিস নম্বর', 'type' => 'text', 'required' => true, 'max_length' => 32,
                    // Flow fix X7: a customer asking for a price rarely has the chassis number; it is needed when the proposal is submitted.
                    'required_at' => 'proposal'],
                ['key' => 'engine_cc', 'label_en' => 'Engine capacity (cc)', 'label_bn' => 'ইঞ্জিন ক্ষমতা (সিসি)', 'type' => 'integer', 'required' => true, 'min' => 50, 'max' => 10000],
                ['key' => 'seats', 'label_en' => 'Seats', 'label_bn' => 'আসন সংখ্যা', 'type' => 'integer', 'required' => true, 'min' => 1, 'max' => 60],
                ['key' => 'year_of_manufacture', 'label_en' => 'Year of manufacture', 'label_bn' => 'তৈরির বছর', 'type' => 'integer', 'required' => true, 'min' => 1950, 'max' => 2100],
                ['key' => 'driver_age', 'label_en' => 'Driver age', 'label_bn' => 'চালকের বয়স', 'type' => 'integer', 'required' => true, 'min' => 18, 'max' => 99],
                $sumInsured,
                ['key' => 'ncb_years', 'label_en' => 'Claim-free years', 'label_bn' => 'দাবিমুক্ত বছর', 'type' => 'integer', 'required' => false, 'min' => 0, 'max' => 50],
            ],
            'fire' => [
                ['key' => 'occupancy', 'label_en' => 'Occupancy', 'label_bn' => 'ব্যবহারের ধরন', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'dwelling', 'label_en' => 'Dwelling', 'label_bn' => 'বাসস্থান'],
                    ['value' => 'shop', 'label_en' => 'Shop or office', 'label_bn' => 'দোকান বা অফিস'],
                    ['value' => 'warehouse', 'label_en' => 'Warehouse', 'label_bn' => 'গুদাম'],
                    ['value' => 'factory', 'label_en' => 'Factory', 'label_bn' => 'কারখানা'],
                ]],
                ['key' => 'construction_class', 'label_en' => 'Construction class', 'label_bn' => 'নির্মাণ শ্রেণি', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'class_1', 'label_en' => 'Class 1 (brick and concrete)', 'label_bn' => 'শ্রেণি ১ (ইট ও কংক্রিট)'],
                    ['value' => 'class_2', 'label_en' => 'Class 2 (mixed)', 'label_bn' => 'শ্রেণি ২ (মিশ্র)'],
                    ['value' => 'class_3', 'label_en' => 'Class 3 (tin or wood)', 'label_bn' => 'শ্রেণি ৩ (টিন বা কাঠ)'],
                ]],
                ['key' => 'address', 'label_en' => 'Risk address', 'label_bn' => 'ঝুঁকির ঠিকানা', 'type' => 'text', 'required' => true],
                $sumInsured,
            ],
            'marine_cargo' => [
                ['key' => 'voyage_type', 'label_en' => 'Voyage', 'label_bn' => 'যাত্রার ধরন', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'import', 'label_en' => 'Import', 'label_bn' => 'আমদানি'],
                    ['value' => 'export', 'label_en' => 'Export', 'label_bn' => 'রপ্তানি'],
                    ['value' => 'inland', 'label_en' => 'Inland transit', 'label_bn' => 'অভ্যন্তরীণ পরিবহন'],
                ]],
                ['key' => 'conveyance', 'label_en' => 'Conveyance', 'label_bn' => 'পরিবহন মাধ্যম', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'sea', 'label_en' => 'Sea', 'label_bn' => 'সমুদ্র'],
                    ['value' => 'air', 'label_en' => 'Air', 'label_bn' => 'আকাশ'],
                    ['value' => 'road', 'label_en' => 'Road or rail', 'label_bn' => 'সড়ক বা রেল'],
                ]],
                ['key' => 'commodity', 'label_en' => 'Commodity', 'label_bn' => 'পণ্য', 'type' => 'text', 'required' => true],
                ['key' => 'from_port', 'label_en' => 'From', 'label_bn' => 'যাত্রাস্থল', 'type' => 'text', 'required' => true],
                ['key' => 'to_port', 'label_en' => 'To', 'label_bn' => 'গন্তব্য', 'type' => 'text', 'required' => true],
                $sumInsured,
            ],
            default => [
                ['key' => 'description', 'label_en' => 'Description of the risk', 'label_bn' => 'ঝুঁকির বিবরণ', 'type' => 'text', 'required' => true, 'max_length' => 1000],
                $sumInsured,
            ],
        };
    }

    /** @return list<array<string, mixed>> */
    public static function coverages(string $classCode): array
    {
        return match ($classCode) {
            'motor' => [
                ['code' => 'own_damage', 'name_en' => 'Own damage', 'name_bn' => 'নিজস্ব ক্ষতি', 'basis' => 'sum_insured', 'mandatory' => true, 'sort_order' => 1],
                ['code' => 'third_party', 'name_en' => 'Third-party liability', 'name_bn' => 'তৃতীয় পক্ষের দায়', 'basis' => 'flat', 'mandatory' => true, 'sort_order' => 2],
                ['code' => 'passenger_liability', 'name_en' => 'Passenger liability', 'name_bn' => 'যাত্রী দায়', 'basis' => 'per_unit', 'mandatory' => false, 'sort_order' => 3],
            ],
            'fire' => [
                ['code' => 'fire', 'name_en' => 'Fire and lightning', 'name_bn' => 'অগ্নি ও বজ্রপাত', 'basis' => 'sum_insured', 'mandatory' => true, 'sort_order' => 1],
            ],
            'marine_cargo' => [
                ['code' => 'cargo', 'name_en' => 'Cargo in transit', 'name_bn' => 'পরিবহনকালীন পণ্য', 'basis' => 'sum_insured', 'mandatory' => true, 'sort_order' => 1],
            ],
            default => [
                ['code' => 'basic', 'name_en' => 'Basic cover', 'name_bn' => 'মৌলিক কভার', 'basis' => 'sum_insured', 'mandatory' => true, 'sort_order' => 1],
            ],
        };
    }
}
