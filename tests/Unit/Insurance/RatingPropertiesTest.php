<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;
use App\Modules\Insurance\Rating\Domain\RatingCalculator;
use App\Modules\Insurance\Rating\Domain\RatingRequest;
use App\Modules\Insurance\Rating\Domain\RatingResult;

/**
 * Phase 3 design §0 DECISION "rate() is pure and deterministic" (slice R3), as properties over many generated quotes (fixed seed, so a failure
 * always reproduces): the same request gives the same result, and the premium never falls when only the sum insured rises.
 * The plans are the golden fixtures' (motor has loadings, a band discount, a minimum and rounding; fire applies duties automatically).
 */
const RATING_PROPERTY_SEED = 20260914;

/**
 * @return array{product: array{risk_schema: list<array<string, mixed>>, coverages: list<array{code: string, name_en: string, name_bn: string, mandatory: bool}>}, plan: array<string, mixed>, duties: list<array<string, mixed>>, request: array{risk_inputs: array<string, mixed>}}
 */
function ratingFixture(string $name): array
{
    /** @var array{product: array{risk_schema: list<array<string, mixed>>, coverages: list<array{code: string, name_en: string, name_bn: string, mandatory: bool}>}, plan: array<string, mixed>, duties: list<array<string, mixed>>, request: array{risk_inputs: array<string, mixed>}} $fixture */
    $fixture = json_decode((string) file_get_contents(__DIR__."/../../Fixtures/rating/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $fixture;
}

/**
 * @param array{product: array{risk_schema: list<array<string, mixed>>, coverages: list<array{code: string, name_en: string, name_bn: string, mandatory: bool}>}, plan: array<string, mixed>, duties: list<array<string, mixed>>, request: array{risk_inputs: array<string, mixed>}} $fixture
 * @param array<string, mixed> $inputs
 * @param list<string> $coverages
 */
function rateFixture(array $fixture, array $inputs, array $coverages): RatingResult
{
    return (new RatingCalculator())->calculate(new RatingRequest(
        RatingPlanDefinition::fromArray($fixture['plan']), RiskSchema::fromArray($fixture['product']['risk_schema']), $inputs, '2026-09-15',
        array_map(fn (array $c): array => ['code' => $c['code'], 'name_en' => $c['name_en'], 'name_bn' => $c['name_bn'], 'mandatory' => $c['mandatory']], $fixture['product']['coverages']),
        $coverages, array_map(fn (array $d): DutyDefinition => DutyDefinition::fromArray($d), $fixture['duties']), null, 600_000,
    ));
}

/** @return array{0: array<string, mixed>, 1: list<string>} random motor inputs without the sum insured, and the optional coverages */
function randomMotorQuote(): array
{
    $types = ['private', 'commercial', 'motorcycle'];

    return [[
        'vehicle_type' => $types[mt_rand(0, 2)], 'registration_no' => 'DHA-'.mt_rand(1000, 9999), 'engine_cc' => mt_rand(50, 10000), 'seats' => mt_rand(1, 60),
        'year_of_manufacture' => mt_rand(1990, 2026), 'driver_age' => mt_rand(18, 99), 'ncb_years' => mt_rand(0, 1) === 1 ? mt_rand(0, 50) : null,
    ], mt_rand(0, 1) === 1 ? ['passenger_liability'] : []];
}

it('gives the same result for the same quote, every time', function (): void {
    mt_srand(RATING_PROPERTY_SEED);
    $motor = ratingFixture('01_motor_comprehensive');
    for ($i = 0; $i < 200; $i++) {
        [$inputs, $coverages] = randomMotorQuote();
        $inputs['sum_insured'] = mt_rand(1, 50_000_000_000);
        $first = rateFixture($motor, $inputs, $coverages);
        $second = rateFixture($motor, $inputs, $coverages);

        expect($second)->toEqual($first)
            ->and($second->toArray())->toBe($first->toArray())
            ->and(RatingResult::fromArray($first->toArray())->toArray())->toBe($first->toArray())
            ->and($first->grossPremiumMinor)->toBe($first->netPremiumMinor + $first->dutiesTotalMinor)
            ->and($first->explanation[count($first->explanation) - 1]['running_total_minor'])->toBe($first->grossPremiumMinor);
    }
});

it('never lowers the premium when only the sum insured rises', function (string $fixtureName): void {
    mt_srand(RATING_PROPERTY_SEED);
    $fixture = ratingFixture($fixtureName);
    $baseInputs = $fixture['request']['risk_inputs'];
    $occupancies = ['dwelling', 'shop', 'warehouse', 'factory'];
    for ($i = 0; $i < 150; $i++) {
        if ($fixtureName === '01_motor_comprehensive') {
            [$inputs, $coverages] = randomMotorQuote();
        } else {
            [$inputs, $coverages] = [[...$baseInputs, 'occupancy' => $occupancies[mt_rand(0, 3)], 'construction_class' => 'class_'.mt_rand(1, 3)], []];
        }
        $sums = array_map(fn (): int => mt_rand(1, 99_999_999_999), range(1, 6));
        sort($sums);
        $previous = null;
        foreach ($sums as $sum) {
            $result = rateFixture($fixture, [...$inputs, 'sum_insured' => $sum], $coverages);
            if ($previous !== null) {
                expect($result->netPremiumMinor)->toBeGreaterThanOrEqual($previous->netPremiumMinor, "net at {$sum}")
                    ->and($result->grossPremiumMinor)->toBeGreaterThanOrEqual($previous->grossPremiumMinor, "gross at {$sum}");
            }
            $previous = $result;
        }
    }
})->with(['01_motor_comprehensive', '02_fire_per_mille_by_occupancy']);
