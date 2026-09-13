<?php

declare(strict_types=1);

use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RateTableDefinition;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingContext;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingExpressions;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingScope;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Rating\Domain\RatingMath;

/**
 * Phase 3 design §1 (slice R2): the rating expression evaluator — the posting-rule language extended with lookup/band — and its integer arithmetic.
 * No floats: division rounds half-even and decimals are refused.
 */
/** @return array<string, RateTableDefinition> */
function ratingTables(): array
{
    return [
        'motor_base' => RateTableDefinition::fromArray(['code' => 'motor_base', 'name' => 'Base', 'dimensions' => ['vehicle_type', 'cc_band'], 'value_type' => 'rate_pm', 'rows' => [
            ['keys' => ['vehicle_type' => 'private', 'cc_band' => 'upto_1300'], 'value_bp' => 250],
            ['keys' => ['vehicle_type' => 'private', 'cc_band' => '1301_1800'], 'value_bp' => 300, 'effective_to' => '2026-07-01'],
            ['keys' => ['vehicle_type' => 'private', 'cc_band' => '1301_1800'], 'value_bp' => 320, 'effective_from' => '2026-07-01'],
        ]]),
        'cc_bands' => RateTableDefinition::fromArray(['code' => 'cc_bands', 'name' => 'Engine bands', 'dimensions' => [], 'value_type' => 'band', 'rows' => [
            ['keys' => [], 'band_from' => 0, 'band_to' => 1301, 'band_label' => 'upto_1300'],
            ['keys' => [], 'band_from' => 1301, 'band_to' => 1801, 'band_label' => '1301_1800'],
            ['keys' => [], 'band_from' => 1801, 'band_to' => null, 'band_label' => 'above_1800'],
        ]]),
        'ncb' => RateTableDefinition::fromArray(['code' => 'ncb', 'name' => 'No-claim bonus', 'dimensions' => [], 'value_type' => 'band', 'rows' => [
            ['keys' => [], 'band_from' => 0, 'band_to' => 1, 'band_label' => 'none', 'value_bp' => 0],
            ['keys' => [], 'band_from' => 1, 'band_to' => 2, 'band_label' => 'one', 'value_bp' => 1000],
            ['keys' => [], 'band_from' => 2, 'band_to' => null, 'band_label' => 'more', 'value_bp' => 2000],
        ]]),
        'seat' => RateTableDefinition::fromArray(['code' => 'seat', 'name' => 'Per seat', 'dimensions' => ['vehicle_type'], 'value_type' => 'flat', 'rows' => [
            ['keys' => ['vehicle_type' => 'private'], 'value_minor' => 5_000],
        ]]),
    ];
}

/** @param array<string, mixed> $risk */
function rateExpression(string $expression, array $risk = [], string $day = '2026-09-01', int $running = 0): mixed
{
    $expressions = new RatingExpressions();
    $variables = ['risk' => new RatingScope('risk', $risk), 'coverage' => new RatingScope('coverage', []), 'sum_insured' => (int) ($risk['sum_insured'] ?? 0),
        'running' => new RatingScope('running', ['premium' => $running]), 'steps' => new RatingScope('steps', [])];
    $context = new RatingContext(ratingTables(), $day, fn (string $code): int => $code === 'vat' ? RatingMath::pct($running, 1500) : throw new RatingFailed('DUTY_NOT_FOUND', $code));

    return str_starts_with($expression, '?') ? $expressions->condition(substr($expression, 1), $variables, $context) : $expressions->amount($expression, $variables, $context);
}

/** @param array<string, mixed> $risk */
function rateFailure(string $expression, array $risk = [], string $day = '2026-09-01'): string
{
    return thrownBy(fn () => rateExpression($expression, $risk, $day), RatingFailed::class)->reasonCode;
}

it('rounds percentages, per-mille rates and division half-even in integers', function (): void {
    expect(RatingMath::pct(1_000_005, 1500))->toBe(150_001) // 150000.75 → 150001
        ->and(RatingMath::pct(10, 5000))->toBe(5)
        ->and(RatingMath::pct(1, 5000))->toBe(0)   // 0.5 → 0 (even)
        ->and(RatingMath::pct(3, 5000))->toBe(2)   // 1.5 → 2 (even)
        ->and(RatingMath::pct(-3, 5000))->toBe(-2)
        ->and(RatingMath::perMille(150_000_000, 250))->toBe(375_000) // 2.50 ‰ of 1,500,000.00
        ->and(RatingMath::divide(7, 2))->toBe(4)
        ->and(RatingMath::divide(5, 2))->toBe(2)
        ->and(RatingMath::divide(-7, 2))->toBe(-4)
        ->and(RatingMath::divide(7, -2))->toBe(-4)
        ->and(RatingMath::roundTo(123_450, 100))->toBe(123_400)
        ->and(RatingMath::roundTo(123_550, 100))->toBe(123_600)
        ->and(RatingMath::roundTo(123_451, 100))->toBe(123_500)
        ->and(RatingMath::ceilTo(123_401, 100))->toBe(123_500)
        ->and(RatingMath::ceilTo(-150, 100))->toBe(-100)
        ->and(RatingMath::floorTo(123_499, 100))->toBe(123_400)
        ->and(RatingMath::floorTo(-150, 100))->toBe(-200)
        ->and(fn () => RatingMath::divide(1, 0))->toThrow(RatingFailed::class)
        ->and(fn () => RatingMath::multiply(PHP_INT_MAX, 2))->toThrow(RatingFailed::class, 'too large')
        ->and(fn () => RatingMath::add(PHP_INT_MAX, 1))->toThrow(RatingFailed::class, 'too large');
});

it('evaluates the rating functions over risk inputs', function (): void {
    $risk = ['vehicle_type' => 'private', 'engine_cc' => 1500, 'sum_insured' => 150_000_000, 'seats' => 4, 'ncb_years' => null];

    expect(rateExpression("per_mille(sum_insured, lookup('motor_base', risk.vehicle_type, band(risk.engine_cc, 'cc_bands')))", $risk))->toBe(480_000)
        ->and(rateExpression("lookup('seat', risk.vehicle_type) * risk.seats", $risk))->toBe(20_000)
        ->and(rateExpression("pct(running.premium, band_value(risk.ncb_years ?? 0, 'ncb'))", $risk, running: 100_000))->toBe(0)
        ->and(rateExpression("pct(running.premium, band_value(3, 'ncb'))", $risk, running: 100_000))->toBe(20_000)
        ->and(rateExpression('max(running.premium, 250000) - running.premium', $risk, running: 100_000))->toBe(150_000)
        ->and(rateExpression("round_to(div(running.premium, 3), 100)", $risk, running: 100_000))->toBe(33_300)
        ->and(rateExpression("duty('vat')", $risk, running: 100_001))->toBe(15_000)
        ->and(rateExpression('?risk.engine_cc > 1300 and risk.vehicle_type in ["private", "commercial"]', $risk))->toBeTrue();
});

it('uses the row in force on the rating date', function (): void {
    $risk = ['vehicle_type' => 'private', 'engine_cc' => 1500];

    expect(rateExpression("lookup('motor_base', risk.vehicle_type, band(risk.engine_cc, 'cc_bands'))", $risk, '2026-06-30'))->toBe(300)
        ->and(rateExpression("lookup('motor_base', risk.vehicle_type, band(risk.engine_cc, 'cc_bands'))", $risk, '2026-07-01'))->toBe(320);
});

it('finds bands with the lower edge inside and the upper edge outside', function (): void {
    expect(rateExpression("band(1300, 'cc_bands') == 'upto_1300' ? 1 : 0"))->toBe(1)
        ->and(rateExpression("band(1301, 'cc_bands') == '1301_1800' ? 1 : 0"))->toBe(1)
        ->and(rateExpression("band(1800, 'cc_bands') == '1301_1800' ? 1 : 0"))->toBe(1)
        ->and(rateExpression("band(1801, 'cc_bands') == 'above_1800' ? 1 : 0"))->toBe(1)
        ->and(rateExpression("band(99999, 'cc_bands') == 'above_1800' ? 1 : 0"))->toBe(1)
        ->and(rateFailure("band(-1, 'cc_bands') == 'x' ? 1 : 0"))->toBe('BAND_NOT_FOUND');
});

it('fails lookups clearly', function (): void {
    $miss = thrownBy(fn () => rateExpression("lookup('motor_base', 'commercial', 'upto_1300')"), RatingFailed::class);

    expect($miss->reasonCode)->toBe('RATE_NOT_FOUND')
        ->and($miss->getMessage())->toContain('motor_base')->toContain('vehicle_type=commercial')->toContain('cc_band=upto_1300')
        ->and(rateFailure("lookup('motor_base', 'private')"))->toBe('RATING_EXPRESSION_INVALID')
        ->and(rateFailure("lookup('nothing', 'private')"))->toBe('RATE_TABLE_UNKNOWN')
        ->and(rateFailure("lookup('cc_bands', 'private')"))->toBe('RATE_TABLE_TYPE')
        ->and(rateFailure("band(5, 'seat') == '' ? 0 : 1"))->toBe('RATE_TABLE_TYPE')
        ->and(rateFailure('risk.engine_cc'))->toBe('RISK_INPUT_MISSING')
        ->and(rateFailure("duty('stamp')"))->toBe('DUTY_NOT_FOUND');
});

it('refuses anything but integer arithmetic over the rating variables', function (string $expression, string $reason): void {
    expect(rateFailure($expression, ['vehicle_type' => 'private', 'engine_cc' => 1500, 'sum_insured' => 100]))->toBe($reason);
})->with([
    'division operator' => ['sum_insured / 3', 'RATING_EXPRESSION_INVALID'],
    'modulo' => ['sum_insured % 3', 'RATING_EXPRESSION_INVALID'],
    'power' => ['sum_insured ** 2', 'RATING_EXPRESSION_INVALID'],
    'decimal constant' => ['sum_insured * 1.5', 'RATING_EXPRESSION_INVALID'],
    'string concatenation' => ['risk.vehicle_type ~ "x"', 'RATING_EXPRESSION_INVALID'],
    'method call' => ['risk.__get("engine_cc")', 'RATING_EXPRESSION_INVALID'],
    'unknown function' => ['constant("PHP_INT_MAX")', 'RATING_EXPRESSION_INVALID'],
    'unknown variable' => ['payload.amount', 'RATING_EXPRESSION_INVALID'],
    'the hidden context' => ['__rating', 'RATING_EXPRESSION_INVALID'],
    'table named by a variable' => ['lookup(risk.vehicle_type, "private")', 'RATING_EXPRESSION_INVALID'],
    'text amount' => ['risk.vehicle_type', 'RATING_EXPRESSION_NOT_INTEGER'],
    'text into pct' => ['pct(risk.vehicle_type, 100)', 'RATING_EXPRESSION_INVALID'],
    'syntax' => ['pct(sum_insured,', 'RATING_EXPRESSION_INVALID'],
    'overflowing product' => ['4611686018427387904 * sum_insured', 'RATING_EXPRESSION_NOT_INTEGER'],
    'condition not boolean' => ['?sum_insured', 'RATING_CONDITION_NOT_BOOLEAN'],
]);

it('lists what stops a plan being approved', function (): void {
    $plan = RatingPlanDefinition::fromArray(['code' => 'P', 'name' => 'P', 'class_code' => 'motor', 'effective_from' => '2026-01-01', 'tables' => [
        ['code' => 'rates', 'name' => 'Rates', 'dimensions' => ['vehicle_type'], 'value_type' => 'rate_pct', 'rows' => [
            ['keys' => ['vehicle_type' => 'private'], 'value_bp' => 100], ['keys' => ['vehicle_type' => 'private'], 'value_bp' => 200], ['keys' => ['colour' => 'red'], 'value_minor' => 1],
        ]],
        ['code' => 'bands', 'name' => 'Bands', 'dimensions' => [], 'value_type' => 'band', 'rows' => [
            ['keys' => [], 'band_from' => 0, 'band_to' => 10, 'band_label' => 'a'], ['keys' => [], 'band_from' => 5, 'band_to' => null, 'band_label' => 'b'],
        ]],
    ], 'steps' => [
        ['order_no' => 1, 'code' => 'vat', 'kind' => 'tax', 'expression' => "duty('vat')", 'label_en' => 'VAT', 'label_bn' => 'ভ্যাট'],
        ['order_no' => 2, 'code' => 'load', 'kind' => 'loading', 'expression' => "pct(running.premium, lookup('bands', 1))", 'label_en' => 'Load', 'label_bn' => 'লোড'],
        ['order_no' => 3, 'code' => 'extra', 'kind' => 'coverage', 'expression' => "lookup('missing', 'x')", 'label_en' => 'Extra', 'label_bn' => 'অতিরিক্ত'],
        ['order_no' => 4, 'code' => 'bad', 'kind' => 'discount', 'expression' => 'running.premium / 2', 'label_en' => 'Bad', 'label_bn' => 'খারাপ'],
    ]]);

    expect(implode("\n", $plan->problems(new RatingExpressions())))
        ->toContain('at least one base step')
        ->toContain('two rows for {"vehicle_type":"private"}')
        ->toContain('row 3: keys must be exactly vehicle_type')
        ->toContain('a rate table row has value_bp only')
        ->toContain('bands a and b overlap')
        ->toContain('Step load (loading) comes after a later kind of step')
        ->toContain('Step load uses lookup() on table bands (band)')
        ->toContain('Coverage step extra must name the coverage')
        ->toContain('Step extra uses rate table missing')
        ->toContain('Step bad: Expression \'running.premium / 2\' uses the operator /');
});

it('works out duties by basis, class and date', function (): void {
    $vat = DutyDefinition::fromArray(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['motor', 'fire'], 'effective_from' => '2026-01-01',
        'effective_to' => '2027-01-01', 'label_en' => 'VAT', 'label_bn' => 'ভ্যাট']);
    $stamp = DutyDefinition::fromArray(['code' => 'stamp', 'basis' => 'per_sum_insured_band', 'bands' => [['from' => 0, 'to' => 100_000_00, 'amount_minor' => 50_00],
        ['from' => 100_000_00, 'to' => null, 'amount_minor' => 100_00]], 'class_codes' => ['fire'], 'effective_from' => '2026-01-01', 'label_en' => 'Stamp', 'label_bn' => 'স্ট্যাম্প']);

    expect($vat->amountFor(100_001, 0))->toBe(15_000)
        ->and($vat->appliesTo('motor', '2026-01-01'))->toBeTrue()
        ->and($vat->appliesTo('motor', '2027-01-01'))->toBeFalse()
        ->and($vat->appliesTo('marine_cargo', '2026-06-01'))->toBeFalse()
        ->and($vat->verify)->toBeTrue()
        ->and($stamp->amountFor(0, 99_999_99))->toBe(50_00)
        ->and($stamp->amountFor(0, 100_000_00))->toBe(100_00)
        ->and(fn () => DutyDefinition::fromArray(['code' => 'vat', 'basis' => 'pct_of_premium', 'amount_minor' => 5, 'class_codes' => ['motor'], 'effective_from' => '2026-01-01',
            'label_en' => 'VAT', 'label_bn' => 'ভ্যাট']))->toThrow(RatingFailed::class)
        ->and(fn () => DutyDefinition::fromArray(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 15.0, 'class_codes' => ['motor'], 'effective_from' => '2026-01-01',
            'label_en' => 'VAT', 'label_bn' => 'ভ্যাট']))->toThrow(RatingFailed::class);
});
