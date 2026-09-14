<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Product\Domain\Enums\RiskFieldType;
use App\Modules\Insurance\Product\Domain\Enums\RiskStage;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchemaInvalid;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** Phase 3 design §1 product_versions.risk_schema (slice R1): the schema shape and validation of risk inputs against it. */
function motorSchema(): RiskSchema
{
    return RiskSchema::fromArray([
        ['key' => 'vehicle_type', 'label_en' => 'Vehicle type', 'label_bn' => 'যানবাহনের ধরন', 'type' => 'select', 'required' => true, 'options' => [
            ['value' => 'private', 'label_en' => 'Private', 'label_bn' => 'ব্যক্তিগত'], ['value' => 'commercial', 'label_en' => 'Commercial', 'label_bn' => 'বাণিজ্যিক'],
        ]],
        ['key' => 'registration_no', 'label_en' => 'Registration', 'label_bn' => 'নিবন্ধন', 'type' => 'text', 'required' => true, 'max_length' => 10],
        ['key' => 'engine_cc', 'label_en' => 'Engine cc', 'label_bn' => 'সিসি', 'type' => 'integer', 'required' => true, 'min' => 50, 'max' => 10000],
        ['key' => 'sum_insured', 'label_en' => 'Sum insured', 'label_bn' => 'বিমাকৃত অঙ্ক', 'type' => 'money', 'required' => true],
        ['key' => 'first_registered', 'label_en' => 'First registered', 'label_bn' => 'প্রথম নিবন্ধন', 'type' => 'date', 'required' => false],
        ['key' => 'garaged', 'label_en' => 'Garaged', 'label_bn' => 'গ্যারেজে রাখা', 'type' => 'boolean', 'required' => false],
    ]);
}

/**
 * @param array<string, mixed> $inputs
 * @return array<string, string>
 */
function riskErrors(array $inputs): array
{
    return thrownBy(fn () => motorSchema()->validate($inputs), RiskInputsInvalid::class)->errors;
}

it('reads a schema and writes it back unchanged', function (): void {
    $schema = motorSchema();

    expect($schema->fields)->toHaveCount(6)
        ->and($schema->field('engine_cc')?->type)->toBe(RiskFieldType::Integer)
        ->and($schema->field('vehicle_type')?->optionValues())->toBe(['private', 'commercial'])
        ->and(RiskSchema::fromArray($schema->toArray()))->toEqual($schema)
        ->and(RiskSchema::empty()->isEmpty())->toBeTrue();
});

it('refuses malformed schemas', function (array $definition, string $message): void {
    expect(fn () => RiskSchema::fromArray($definition))->toThrow(RiskSchemaInvalid::class, $message);
})->with([
    'not a list' => [['a' => ['key' => 'x']], 'list of fields'],
    'bad key' => [[['key' => 'Engine CC', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text']], 'snake_case key'],
    'duplicate key' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text'], ['key' => 'a', 'label_en' => 'y', 'label_bn' => 'y', 'type' => 'text']], 'appears twice'],
    'no Bangla label' => [[['key' => 'a', 'label_en' => 'x', 'type' => 'text']], 'label_bn'],
    'unknown type' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'decimal']], 'valid type'],
    'select without options' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'select']], 'list of options'],
    'options on text' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'options' => []]], 'only select'],
    'min on text' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'min' => 1]], 'min must be an integer'],
    'float bound' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'integer', 'max' => 1.5]], 'max must be an integer'],
    'min above max' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'integer', 'min' => 5, 'max' => 1]], 'min is above max'],
    'negative money' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'money', 'min' => -1]], 'below zero'],
    'unknown setting' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'placeholder' => 'x']], 'unknown settings'],
    'required_at not a stage' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'required' => true, 'required_at' => 'policy']], 'required_at is quote or proposal'],
    'proposal stage on an optional field' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'required_at' => 'proposal']], 'only for a required field'],
    'default not an option' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'select', 'options' => [['value' => 'p', 'label_en' => 'P', 'label_bn' => 'P']], 'default' => 'q']], 'default is not a valid value'],
    'default out of bounds' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'integer', 'max' => 5, 'default' => 6]], 'default is not a valid value'],
    'empty default' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'default' => '']], 'default is not a valid value'],
    'required not bool' => [[['key' => 'a', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'text', 'required' => 'yes']], 'required must be'],
]);

it('normalises valid inputs in schema order: integers and money as int, booleans as bool, absent optional fields as null', function (): void {
    expect(motorSchema()->validate(['sum_insured' => '150000000', 'engine_cc' => 1500, 'registration_no' => 'DHA-1234', 'vehicle_type' => 'private', 'garaged' => 'true']))
        ->toBe(['vehicle_type' => 'private', 'registration_no' => 'DHA-1234', 'engine_cc' => 1500, 'sum_insured' => 150_000_000, 'first_registered' => null, 'garaged' => true]);
});

it('lists every problem with the inputs', function (): void {
    expect(riskErrors(['vehicle_type' => 'bus', 'engine_cc' => 12.5, 'sum_insured' => -1, 'first_registered' => '2026-02-30', 'garaged' => 'maybe', 'colour' => 'red']))->toBe([
        'colour' => 'UNKNOWN_FIELD', 'vehicle_type' => 'NOT_AN_OPTION', 'registration_no' => 'REQUIRED', 'engine_cc' => 'NOT_INTEGER', 'sum_insured' => 'BELOW_MIN',
        'first_registered' => 'NOT_A_DATE', 'garaged' => 'NOT_BOOLEAN',
    ]);
});

it('checks integer bounds inclusively and text length', function (): void {
    $valid = ['vehicle_type' => 'private', 'registration_no' => 'DHA', 'sum_insured' => 0];

    expect(motorSchema()->validate([...$valid, 'engine_cc' => 50])['engine_cc'])->toBe(50)
        ->and(motorSchema()->validate([...$valid, 'engine_cc' => 10000])['engine_cc'])->toBe(10000)
        ->and(riskErrors([...$valid, 'engine_cc' => 49]))->toBe(['engine_cc' => 'BELOW_MIN'])
        ->and(riskErrors([...$valid, 'engine_cc' => '10001']))->toBe(['engine_cc' => 'ABOVE_MAX'])
        ->and(riskErrors([...$valid, 'engine_cc' => '1e3']))->toBe(['engine_cc' => 'NOT_INTEGER'])
        ->and(riskErrors([...$valid, 'engine_cc' => 100, 'registration_no' => 'DHA-12345678']))->toBe(['registration_no' => 'TOO_LONG'])
        ->and(riskErrors([...$valid, 'engine_cc' => 100, 'registration_no' => '']))->toBe(['registration_no' => 'REQUIRED'])
        ->and(riskErrors([...$valid, 'engine_cc' => 100, 'vehicle_type' => 1]))->toBe(['vehicle_type' => 'NOT_AN_OPTION']);
});

it('flow fix X7: requires a proposal-stage field only when validating for the proposal, and keeps the form default out of validation', function (): void {
    $schema = RiskSchema::fromArray([...motorSchema()->toArray(),
        ['key' => 'chassis_no', 'label_en' => 'Chassis', 'label_bn' => 'চেসিস', 'type' => 'text', 'required' => true, 'required_at' => 'proposal', 'max_length' => 32],
        ['key' => 'use', 'label_en' => 'Use', 'label_bn' => 'ব্যবহার', 'type' => 'select', 'required' => false, 'default' => 'own', 'options' => [
            ['value' => 'own', 'label_en' => 'Own', 'label_bn' => 'নিজ'], ['value' => 'hire', 'label_en' => 'Hire', 'label_bn' => 'ভাড়া'],
        ]],
    ]);
    $inputs = ['vehicle_type' => 'private', 'registration_no' => 'DHA-1', 'engine_cc' => 1500, 'sum_insured' => 100];

    expect($schema->validate($inputs))->toMatchArray(['chassis_no' => null, 'use' => null])
        ->and(thrownBy(fn () => $schema->validate($inputs, RiskStage::Proposal), RiskInputsInvalid::class)->errors)->toBe(['chassis_no' => 'REQUIRED'])
        ->and($schema->validate([...$inputs, 'chassis_no' => 'CH-1'], RiskStage::Proposal)['chassis_no'])->toBe('CH-1')
        ->and(thrownBy(fn () => $schema->validate([...$inputs, 'registration_no' => '']), RiskInputsInvalid::class)->errors)->toBe(['registration_no' => 'REQUIRED'])
        ->and($schema->field('chassis_no')?->requiredAt)->toBe(RiskStage::Proposal)
        ->and($schema->field('use')?->default)->toBe('own')
        ->and(array_slice($schema->toArray(), -2))->toBe([
            ['key' => 'chassis_no', 'label_en' => 'Chassis', 'label_bn' => 'চেসিস', 'type' => 'text', 'required' => true, 'max_length' => 32, 'required_at' => 'proposal'],
            ['key' => 'use', 'label_en' => 'Use', 'label_bn' => 'ব্যবহার', 'type' => 'select', 'required' => false, 'options' => [
                ['value' => 'own', 'label_en' => 'Own', 'label_bn' => 'নিজ'], ['value' => 'hire', 'label_en' => 'Hire', 'label_bn' => 'ভাড়া'],
            ], 'default' => 'own'],
        ])
        ->and(RiskSchema::fromArray($schema->toArray()))->toEqual($schema)
        ->and(motorSchema()->toArray()[0])->not->toHaveKeys(['required_at', 'default']);
    // A money default is minor units, normalised like an input.
    expect(RiskSchema::fromArray([['key' => 'si', 'label_en' => 'x', 'label_bn' => 'x', 'type' => 'money', 'default' => '500']])->field('si')?->default)->toBe(500);
});

it('reads duty profiles that exclude named duties only', function (): void {
    expect(DutyProfile::fromArray(null)->applies('vat'))->toBeTrue()
        ->and(DutyProfile::fromArray(['exclude' => ['levy']])->applies('levy'))->toBeFalse()
        ->and(DutyProfile::fromArray(['exclude' => ['levy']])->applies('stamp'))->toBeTrue()
        ->and(fn () => DutyProfile::fromArray(['exclude' => ['income_tax']]))->toThrow(BusinessRuleViolation::class)
        ->and(fn () => DutyProfile::fromArray(['include' => ['vat']]))->toThrow(BusinessRuleViolation::class);
});
