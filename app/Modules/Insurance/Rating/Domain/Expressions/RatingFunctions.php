<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Expressions;

use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Rating\Domain\RatingMath;
use LogicException;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

/**
 * Integer functions for rating step expressions (Phase 3 design §1), next to the ExpressionLanguage built-ins `min` and `max`:
 * - `lookup('table', key1, key2…)`: the value of the row matching the keys, in dimension order (rate in bp or amount in minor units);
 * - `band(value, 'table')`: the label of the band [from, to) containing the value; `band_value(value, 'table')`: that band's value;
 * - `pct(base, bp)`: base × bp / 10 000; `per_mille(base, rate)`: base × rate / 100 000 (rate in hundredths of ‰); `div(a, b)`: a / b — all half-even;
 * - `round_to(value, unit)` (half-even), `ceil_to(value, unit)`, `floor_to(value, unit)`: to a multiple of unit (100 = 1.00);
 * - `duty('vat')`: the duty in force for the plan's class on the net premium so far (duty and tax steps).
 * Arguments must be integers where amounts are expected: a float is refused, never rounded silently. Expressions are evaluated, never compiled.
 */
final class RatingFunctions implements ExpressionFunctionProviderInterface
{
    public const NAMES = ['lookup', 'band', 'band_value', 'pct', 'per_mille', 'div', 'round_to', 'ceil_to', 'floor_to', 'duty', 'min', 'max'];

    /** Functions whose first (lookup) or second argument names a rate table. */
    public const TABLE_ARGUMENT = ['lookup' => 0, 'band' => 1, 'band_value' => 1];

    /** @return list<ExpressionFunction> */
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction('lookup', self::notCompilable(...), static function (array $variables, mixed $table, mixed ...$keys): int {
                foreach ($keys as $key) {
                    if (! is_int($key) && ! is_string($key)) {
                        throw new RatingFailed('RATING_EXPRESSION_INVALID', 'lookup() keys must be text or whole numbers.');
                    }
                }

                return self::context($variables)->table(self::text($table, 'lookup'))->lookup(array_values($keys), self::context($variables)->day);
            }),
            new ExpressionFunction('band', self::notCompilable(...), static fn (array $variables, mixed $value, mixed $table): string => (string) self::context($variables)
                ->table(self::text($table, 'band'))->band(self::int($value, 'band'), self::context($variables)->day)->bandLabel),
            new ExpressionFunction('band_value', self::notCompilable(...), static function (array $variables, mixed $value, mixed $table): int {
                $code = self::text($table, 'band_value');

                return self::context($variables)->table($code)->band(self::int($value, 'band_value'), self::context($variables)->day)->value()
                    ?? throw new RatingFailed('RATE_NOT_FOUND', "The band of table {$code} containing {$value} has no value.");
            }),
            new ExpressionFunction('pct', self::notCompilable(...), static fn (array $variables, mixed $base, mixed $bp): int => RatingMath::pct(self::int($base, 'pct'), self::int($bp, 'pct'))),
            new ExpressionFunction('per_mille', self::notCompilable(...),
                static fn (array $variables, mixed $base, mixed $rate): int => RatingMath::perMille(self::int($base, 'per_mille'), self::int($rate, 'per_mille'))),
            new ExpressionFunction('div', self::notCompilable(...), static fn (array $variables, mixed $a, mixed $b): int => RatingMath::divide(self::int($a, 'div'), self::int($b, 'div'))),
            new ExpressionFunction('round_to', self::notCompilable(...),
                static fn (array $variables, mixed $value, mixed $unit): int => RatingMath::roundTo(self::int($value, 'round_to'), self::int($unit, 'round_to'))),
            new ExpressionFunction('ceil_to', self::notCompilable(...),
                static fn (array $variables, mixed $value, mixed $unit): int => RatingMath::ceilTo(self::int($value, 'ceil_to'), self::int($unit, 'ceil_to'))),
            new ExpressionFunction('floor_to', self::notCompilable(...),
                static fn (array $variables, mixed $value, mixed $unit): int => RatingMath::floorTo(self::int($value, 'floor_to'), self::int($unit, 'floor_to'))),
            new ExpressionFunction('duty', self::notCompilable(...), static fn (array $variables, mixed $code): int => self::context($variables)->duty(self::text($code, 'duty'))),
        ];
    }

    /** @param array<mixed> $variables */
    private static function context(array $variables): RatingContext
    {
        $context = $variables[RatingContext::VARIABLE] ?? null;
        if (! $context instanceof RatingContext) {
            throw new LogicException('Rating functions need a rating context.');
        }

        return $context;
    }

    private static function int(mixed $value, string $function): int
    {
        if (! is_int($value)) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', "{$function}() needs whole numbers (minor units or basis points), got ".get_debug_type($value).'.');
        }

        return $value;
    }

    private static function text(mixed $value, string $function): string
    {
        if (! is_string($value)) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', "{$function}() needs a table or duty code in quotes.");
        }

        return $value;
    }

    private static function notCompilable(): never
    {
        throw new LogicException('Rating expressions are evaluated, not compiled.');
    }
}
