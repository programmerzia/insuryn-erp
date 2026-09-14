<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application\Templates;

/**
 * Tariff templates (slice R3 demo tariffs; gap fix GA-18 made them a template a new tenant starts from in the setup wizard): rating plan definitions
 * for motor, fire, marine cargo and misc, and the duties on premium. The demo seeders (`Database\Seeders\DemoRatingPlans`) and the setup wizard's
 * product step read the same definitions.
 *
 * EVERY VALUE HERE IS AN ILLUSTRATIVE PLACEHOLDER — not an IDRA tariff or NBR duty. Plans carry `verify = true`, duties `verify = true` and
 * `source = placeholder_verify`; the list is in docs/PROGRESS.md (R3, "Placeholder values to verify").
 */
final class TariffTemplates
{
    public const SOURCE = 'placeholder_verify';

    /**
     * The plan definition for a product class, or null when no template exists for it.
     *
     * @return array<string, mixed>|null
     */
    public static function planFor(string $classCode, string $from): ?array
    {
        foreach (self::plans($from) as $definition) {
            if ($definition['class_code'] === $classCode) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The duties of a product class, each narrowed to that class (a template duty shared by several classes, such as VAT, is recorded per class).
     *
     * @return list<array<string, mixed>>
     */
    public static function dutiesFor(string $classCode, string $from): array
    {
        $duties = [];
        foreach (self::duties($from) as $duty) {
            if (in_array($classCode, (array) $duty['class_codes'], true)) {
                $duties[] = [...$duty, 'class_codes' => [$classCode]];
            }
        }

        return $duties;
    }

    /** @return list<array<string, mixed>> */
    public static function duties(string $from = '2026-01-01'): array
    {
        $duty = fn (array $values): array => [...$values, 'effective_from' => $from, 'verify' => true, 'source' => self::SOURCE];

        return [
            $duty(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['motor', 'fire', 'marine_cargo', 'misc'], 'label_en' => 'VAT 15%', 'label_bn' => 'মূসক ১৫%']),
            $duty(['code' => 'stamp', 'basis' => 'flat_per_policy', 'amount_minor' => 5_000, 'class_codes' => ['motor'], 'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক']),
            $duty(['code' => 'stamp', 'basis' => 'per_sum_insured_band', 'bands' => [['from' => 0, 'to' => 1_000_000_000, 'amount_minor' => 20_000],
                ['from' => 1_000_000_000, 'to' => 5_000_000_000, 'amount_minor' => 50_000], ['from' => 5_000_000_000, 'to' => null, 'amount_minor' => 100_000]],
                'class_codes' => ['fire'], 'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক']),
            $duty(['code' => 'stamp', 'basis' => 'flat_per_policy', 'amount_minor' => 10_000, 'class_codes' => ['marine_cargo', 'misc'], 'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function plans(string $from = '2026-01-01'): array
    {
        $step = fn (int $order, string $code, string $kind, string $expression, string $en, string $bn, ?string $condition = null, ?string $appliesTo = null): array =>
            ['order_no' => $order, 'code' => $code, 'kind' => $kind, 'expression' => $expression, 'condition' => $condition, 'applies_to' => $appliesTo, 'label_en' => $en, 'label_bn' => $bn];
        $rounding = fn (int $order): array => $step($order, 'rounding', 'rounding', 'round_to(running.premium, 100)', 'Rounded to the nearest taka', 'নিকটতম টাকায় পূর্ণসংখ্যা');
        $header = fn (string $code, string $name, string $class): array => ['code' => $code, 'name' => $name, 'class_code' => $class, 'effective_from' => $from, 'source' => 'company',
            'verify' => true, 'notes' => 'Illustrative placeholder tariff: verify every rate with underwriting and the current IDRA tariff before use.'];
        $byType = fn (array $values): array => array_map(fn (string $type, int $v): array => ['keys' => ['vehicle_type' => $type], 'value_minor' => $v], array_keys($values), $values);
        $band = fn (int $from, ?int $to, string $label, ?int $bp = null): array => ['keys' => [], 'band_from' => $from, 'band_to' => $to, 'band_label' => $label, 'value_bp' => $bp];
        $motorBase = [];
        foreach (['private' => [2000, 2250, 2500], 'commercial' => [2750, 3000, 3250], 'motorcycle' => [1500, 1750, 2000]] as $type => $rates) {
            foreach (['upto_1300', '1301_1800', 'above_1800'] as $i => $cc) {
                $motorBase[] = ['keys' => ['vehicle_type' => $type, 'cc_band' => $cc], 'value_bp' => $rates[$i]];
            }
        }
        $voyage = [];
        foreach (['import' => ['sea' => 150, 'air' => 100, 'road' => 120], 'export' => ['sea' => 120, 'air' => 80, 'road' => 100], 'inland' => ['sea' => 180, 'air' => 120, 'road' => 200]] as $v => $rates) {
            foreach ($rates as $conveyance => $rate) {
                $voyage[] = ['keys' => ['voyage_type' => $v, 'conveyance' => $conveyance], 'value_bp' => $rate];
            }
        }

        return [
            [...$header('MOTOR-TARIFF', 'Motor comprehensive tariff (placeholder)', 'motor'),
                'tables' => [
                    ['code' => 'cc_bands', 'name' => 'Engine capacity bands', 'dimensions' => [], 'value_type' => 'band', 'rows' => [$band(0, 1301, 'upto_1300'), $band(1301, 1801, '1301_1800'), $band(1801, null, 'above_1800')]],
                    ['code' => 'min_premium', 'name' => 'Minimum premium by vehicle type', 'dimensions' => ['vehicle_type'], 'value_type' => 'flat', 'rows' => $byType(['private' => 500_000, 'commercial' => 750_000, 'motorcycle' => 150_000])],
                    ['code' => 'motor_base', 'name' => 'Own damage rate per mille', 'dimensions' => ['vehicle_type', 'cc_band'], 'value_type' => 'rate_pm', 'rows' => $motorBase],
                    ['code' => 'ncb_scale', 'name' => 'No-claim bonus scale', 'dimensions' => [], 'value_type' => 'band', 'rows' => [$band(0, 1, 'ncb_0', 0), $band(1, 2, 'ncb_10', 1000), $band(2, 3, 'ncb_20', 2000), $band(3, null, 'ncb_30', 3000)]],
                    ['code' => 'passenger_seat', 'name' => 'Passenger liability per seat', 'dimensions' => ['vehicle_type'], 'value_type' => 'flat', 'rows' => $byType(['private' => 4_500, 'commercial' => 4_500, 'motorcycle' => 4_500])],
                    ['code' => 'third_party', 'name' => 'Third-party liability', 'dimensions' => ['vehicle_type'], 'value_type' => 'flat', 'rows' => $byType(['private' => 250_000, 'commercial' => 400_000, 'motorcycle' => 90_000])],
                ],
                'steps' => [
                    $step(10, 'own_damage', 'base', "per_mille(sum_insured, lookup('motor_base', risk.vehicle_type, band(risk.engine_cc, 'cc_bands')))", 'Own damage premium', 'নিজস্ব ক্ষতির প্রিমিয়াম', null, 'own_damage'),
                    $step(20, 'third_party', 'coverage', "lookup('third_party', risk.vehicle_type)", 'Third-party liability', 'তৃতীয় পক্ষের দায়', null, 'third_party'),
                    $step(30, 'passenger_liability', 'coverage', "lookup('passenger_seat', risk.vehicle_type) * risk.seats", 'Passenger liability (per seat)', 'যাত্রী দায় (প্রতি আসন)', null, 'passenger_liability'),
                    $step(40, 'young_driver', 'loading', 'pct(running.premium, 1000)', 'Young driver loading 10%', 'তরুণ চালক লোডিং ১০%', 'risk.driver_age < 25'),
                    $step(50, 'old_vehicle', 'loading', 'pct(running.premium, 1500)', 'Vehicle over 10 years loading 15%', '১০ বছরের পুরনো গাড়ি লোডিং ১৫%', 'risk.year_of_manufacture <= 2015'),
                    $step(60, 'no_claim_bonus', 'discount', "pct(running.premium, band_value(risk.ncb_years ?? 0, 'ncb_scale'))", 'No-claim bonus', 'দাবিহীন বোনাস'),
                    $step(70, 'minimum', 'minimum', "lookup('min_premium', risk.vehicle_type)", 'Minimum premium', 'ন্যূনতম প্রিমিয়াম'),
                    $rounding(80),
                    $step(90, 'stamp', 'duty', "duty('stamp')", 'Stamp duty', 'স্ট্যাম্প শুল্ক'),
                    $step(100, 'vat', 'tax', "duty('vat')", 'VAT 15%', 'মূসক ১৫%'),
                ]],
            [...$header('FIRE-TARIFF', 'Fire tariff (placeholder)', 'fire'),
                'tables' => [['code' => 'fire_rate', 'name' => 'Fire rate per mille by occupancy', 'dimensions' => ['occupancy'], 'value_type' => 'rate_pm', 'rows' => [
                    ['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 80], ['keys' => ['occupancy' => 'shop'], 'value_bp' => 150],
                    ['keys' => ['occupancy' => 'warehouse'], 'value_bp' => 200], ['keys' => ['occupancy' => 'factory'], 'value_bp' => 250]]]],
                'steps' => [
                    $step(10, 'fire_basic', 'base', "per_mille(sum_insured, lookup('fire_rate', risk.occupancy))", 'Fire premium by occupancy', 'ব্যবহার অনুযায়ী অগ্নি প্রিমিয়াম', null, 'fire'),
                    $step(20, 'construction', 'loading', 'pct(running.premium, 2500)', 'Class 3 construction loading 25%', 'শ্রেণি ৩ নির্মাণ লোডিং ২৫%', "risk.construction_class == 'class_3'"),
                    $step(30, 'minimum', 'minimum', '100000', 'Minimum premium', 'ন্যূনতম প্রিমিয়াম'),
                    $rounding(40),
                ]],
            [...$header('MARINE-CARGO-TARIFF', 'Marine cargo tariff (placeholder)', 'marine_cargo'),
                'tables' => [['code' => 'voyage_rate', 'name' => 'Voyage rate per mille', 'dimensions' => ['voyage_type', 'conveyance'], 'value_type' => 'rate_pm', 'rows' => $voyage]],
                'steps' => [
                    $step(10, 'voyage', 'base', "per_mille(sum_insured, lookup('voyage_rate', risk.voyage_type, risk.conveyance))", 'Voyage premium', 'যাত্রার প্রিমিয়াম', null, 'cargo'),
                    $step(20, 'minimum', 'minimum', '50000', 'Minimum premium', 'ন্যূনতম প্রিমিয়াম'),
                    $rounding(30),
                ]],
            [...$header('MISC-TARIFF', 'Miscellaneous tariff (placeholder)', 'misc'),
                'tables' => [],
                'steps' => [
                    $step(10, 'basic', 'base', 'per_mille(sum_insured, 300)', 'Premium at 3.00 per mille', 'প্রতি হাজারে ৩.০০ হারে প্রিমিয়াম', null, 'basic'),
                    $step(20, 'minimum', 'minimum', '50000', 'Minimum premium', 'ন্যূনতম প্রিমিয়াম'),
                    $rounding(30),
                ]],
        ];
    }
}
