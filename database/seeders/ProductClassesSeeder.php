<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §1 product_classes: the global catalogue of insurance classes a product version belongs to (DECISION D-18: global like
 * account_roles, no tenant_id). MVP classes (design §7) are active; the rest are listed as `later` so their codes are reserved.
 * Inserted by migration 2026_09_20_000001 and re-run by the seeders (tests truncate global tables too).
 */
final class ProductClassesSeeder extends Seeder
{
    /** code => [name_en, name_bn, insurance_class, status, sort_order] */
    public const CLASSES = [
        'motor' => ['Motor', 'মোটর', 'non_life', 'active', 10],
        'fire' => ['Fire', 'অগ্নি', 'non_life', 'active', 20],
        'marine_cargo' => ['Marine cargo', 'নৌ-পণ্য', 'non_life', 'active', 30],
        'misc' => ['Miscellaneous', 'বিবিধ', 'non_life', 'active', 40],
        'marine_hull' => ['Marine hull', 'নৌযান', 'non_life', 'later', 50],
        'engineering' => ['Engineering', 'প্রকৌশল', 'non_life', 'later', 60],
        'health' => ['Health', 'স্বাস্থ্য', 'non_life', 'later', 70],
        'life' => ['Life', 'জীবন', 'life', 'later', 80],
    ];

    public function run(): void
    {
        foreach (self::CLASSES as $code => [$nameEn, $nameBn, $insuranceClass, $status, $sort]) {
            DB::table('product_classes')->updateOrInsert(['code' => $code],
                ['name_en' => $nameEn, 'name_bn' => $nameBn, 'insurance_class' => $insuranceClass, 'status' => $status, 'sort_order' => $sort]);
        }
    }
}
