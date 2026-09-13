<?php

declare(strict_types=1);

use App\Modules\Insurance\Rating\Application\RatingPlanDiff;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;

/**
 * Phase 3 design §6 tariff editor "diff view" (slice R10a): what changed between two versions of a plan — header dates and fields, rate table rows
 * added, removed and changed (a row is the same row when its keys, or its band start, and its start date match), tables added or removed, steps
 * added, removed and changed by code. Pure: two definitions in, a plain array out.
 */
/** @param array<string, mixed> $overrides */
function diffPlan(array $overrides = []): RatingPlanDefinition
{
    return RatingPlanDefinition::fromArray([
        'code' => 'MOTOR-TARIFF', 'name' => 'Motor tariff', 'class_code' => 'motor', 'version' => 1, 'effective_from' => '2026-01-01', 'source' => 'idra_tariff',
        'tables' => [
            ['code' => 'motor_base', 'name' => 'Own damage rate', 'dimensions' => ['vehicle_type'], 'value_type' => 'rate_pm', 'rows' => [
                ['keys' => ['vehicle_type' => 'private'], 'value_bp' => 2000],
                ['keys' => ['vehicle_type' => 'commercial'], 'value_bp' => 2750],
                ['keys' => ['vehicle_type' => 'motorcycle'], 'value_bp' => 1500],
            ]],
            ['code' => 'ncb_scale', 'name' => 'No-claim bonus', 'dimensions' => [], 'value_type' => 'band', 'rows' => [
                ['keys' => [], 'band_from' => 0, 'band_to' => 1, 'band_label' => 'ncb_0', 'value_bp' => 0],
                ['keys' => [], 'band_from' => 1, 'band_to' => null, 'band_label' => 'ncb_10', 'value_bp' => 1000],
            ]],
        ],
        'steps' => [
            ['order_no' => 10, 'code' => 'base', 'kind' => 'base', 'expression' => "per_mille(sum_insured, lookup('motor_base', risk.vehicle_type))", 'label_en' => 'Own damage', 'label_bn' => 'নিজস্ব ক্ষতি'],
            ['order_no' => 20, 'code' => 'young_driver', 'kind' => 'loading', 'expression' => 'pct(running.premium, 1000)', 'condition' => 'risk.driver_age < 25', 'label_en' => 'Young driver', 'label_bn' => 'তরুণ চালক'],
            ['order_no' => 90, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 100)', 'label_en' => 'Rounding', 'label_bn' => 'পূর্ণসংখ্যা'],
        ],
        ...$overrides,
    ]);
}

/**
 * @param array{tables: list<array<string, mixed>>} $diff
 * @return array<string, mixed>
 */
function diffTable(array $diff, string $code): array
{
    foreach ($diff['tables'] as $table) {
        if ($table['code'] === $code) {
            return $table;
        }
    }

    throw new RuntimeException("No diff for table {$code}.");
}

it('finds nothing to report between identical versions', function (): void {
    $diff = (new RatingPlanDiff())->compare(diffPlan(), diffPlan(['version' => 2]));

    expect($diff['identical'])->toBeTrue()
        ->and($diff['header'])->toBe([])
        ->and($diff['tables'])->toBe([])
        ->and($diff['steps'])->toBe(['added' => [], 'removed' => [], 'changed' => []]);
});

it('lists rows added, removed and changed per table, and date changes on the plan', function (): void {
    $before = diffPlan();
    $after = diffPlan(['version' => 2, 'effective_from' => '2027-01-01', 'effective_to' => '2028-01-01', 'tables' => [
        ['code' => 'motor_base', 'name' => 'Own damage rate', 'dimensions' => ['vehicle_type'], 'value_type' => 'rate_pm', 'rows' => [
            ['keys' => ['vehicle_type' => 'private'], 'value_bp' => 2250],                                   // changed
            ['keys' => ['vehicle_type' => 'commercial'], 'value_bp' => 2750],                                // same
            ['keys' => ['vehicle_type' => 'pickup'], 'value_bp' => 3000],                                    // added (motorcycle removed)
            ['keys' => ['vehicle_type' => 'commercial'], 'value_bp' => 2900, 'effective_from' => '2027-07-01'], // added: same keys, a later start
        ]],
        ['code' => 'ncb_scale', 'name' => 'No-claim bonus scale', 'dimensions' => [], 'value_type' => 'band', 'rows' => [
            ['keys' => [], 'band_from' => 0, 'band_to' => 1, 'band_label' => 'ncb_0', 'value_bp' => 0],
            ['keys' => [], 'band_from' => 1, 'band_to' => 2, 'band_label' => 'ncb_10', 'value_bp' => 1000], // band end changed
        ]],
    ]]);

    $diff = (new RatingPlanDiff())->compare($before, $after);
    $motor = diffTable($diff, 'motor_base');
    $ncb = diffTable($diff, 'ncb_scale');

    expect($diff['identical'])->toBeFalse()
        ->and($diff['header'])->toBe([
            ['field' => 'effective_from', 'before' => '2026-01-01', 'after' => '2027-01-01'],
            ['field' => 'effective_to', 'before' => null, 'after' => '2028-01-01'],
        ])
        ->and($motor['status'])->toBe('changed')
        ->and(array_map(fn (array $r): array => [$r['keys'], $r['value_bp'], $r['effective_from']], $motor['rows']['added']))
        ->toBe([[['vehicle_type' => 'pickup'], 3000, null], [['vehicle_type' => 'commercial'], 2900, '2027-07-01']])
        ->and(array_map(fn (array $r): array => $r['keys'], $motor['rows']['removed']))->toBe([['vehicle_type' => 'motorcycle']])
        ->and($motor['rows']['changed'])->toHaveCount(1)
        ->and($motor['rows']['changed'][0]['before']['value_bp'])->toBe(2000)
        ->and($motor['rows']['changed'][0]['after']['value_bp'])->toBe(2250)
        ->and($motor['rows']['changed'][0]['fields'])->toBe(['value_bp'])
        ->and($motor['changes'])->toBe([])
        ->and($ncb['changes'])->toBe([['field' => 'name', 'before' => 'No-claim bonus', 'after' => 'No-claim bonus scale']])
        ->and($ncb['rows']['changed'][0]['fields'])->toBe(['band_to'])
        ->and($ncb['rows']['added'])->toBe([])
        ->and($ncb['rows']['removed'])->toBe([]);
});

it('lists tables added and removed with their rows, and leaves unchanged tables out', function (): void {
    $after = diffPlan(['tables' => [
        diffPlan()->toArray()['tables'][0],
        ['code' => 'tp_liability', 'name' => 'Third party', 'dimensions' => ['vehicle_type'], 'value_type' => 'flat', 'rows' => [['keys' => ['vehicle_type' => 'private'], 'value_minor' => 250_000]]],
    ]]);

    $diff = (new RatingPlanDiff())->compare(diffPlan(), $after);

    expect(array_map(fn (array $t): array => [$t['code'], $t['status'], count($t['rows']['added']), count($t['rows']['removed'])], $diff['tables']))
        ->toBe([['ncb_scale', 'removed', 0, 2], ['tp_liability', 'added', 1, 0]])
        ->and(diffTable($diff, 'tp_liability')['value_type'])->toBe('flat');
});

it('lists steps added, removed and changed with the fields that changed', function (): void {
    $steps = diffPlan()->toArray()['steps'];
    $steps[1] = [...$steps[1], 'expression' => 'pct(running.premium, 1500)', 'label_en' => 'Driver under 25'];
    unset($steps[2]);
    $steps[] = ['order_no' => 30, 'code' => 'ncb', 'kind' => 'discount', 'expression' => "pct(running.premium, band_value(risk.ncb_years, 'ncb_scale'))", 'condition' => null, 'applies_to' => null,
        'label_en' => 'No-claim bonus', 'label_bn' => 'দাবিহীন বোনাস'];

    $diff = (new RatingPlanDiff())->compare(diffPlan(), diffPlan(['steps' => array_values($steps)]));

    expect(array_map(fn (array $s): string => $s['code'], $diff['steps']['added']))->toBe(['ncb'])
        ->and(array_map(fn (array $s): string => $s['code'], $diff['steps']['removed']))->toBe(['rounding'])
        ->and($diff['steps']['changed'])->toBe([[
            'code' => 'young_driver',
            'changes' => [
                ['field' => 'expression', 'before' => 'pct(running.premium, 1000)', 'after' => 'pct(running.premium, 1500)'],
                ['field' => 'label_en', 'before' => 'Young driver', 'after' => 'Driver under 25'],
            ],
        ]])
        ->and($diff['tables'])->toBe([]);
});
