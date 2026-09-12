<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Design §3.2: tiny safe expression language over payload.* in MINOR UNITS (int).
 * Functions: pct(base, bp) with half-even rounding, min, max, add, sub.
 */
final class AmountEvaluator
{
    public function __construct(private readonly ExpressionLanguage $expr)
    {
        $this->expr->addFunction(new ExpressionFunction('pct',
            fn () => throw new \LogicException('compile unsupported'),
            fn (array $ctx, int $base, int $bp): int => self::pct($base, $bp)));
        foreach (['min', 'max'] as $fn) {
            $this->expr->addFunction(ExpressionFunction::fromPhp($fn));
        }
        $this->expr->addFunction(new ExpressionFunction('add', fn () => '', fn (array $c, int $a, int $b): int => $a + $b));
        $this->expr->addFunction(new ExpressionFunction('sub', fn () => '', fn (array $c, int $a, int $b): int => $a - $b));
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $dims */
    public function evaluate(string $expression, array $payload, array $dims): int
    {
        $v = $this->expr->evaluate($expression, ['payload' => $payload, 'dims' => $dims]);
        if (! is_int($v)) {
            throw new \App\Modules\Accounting\Exceptions\PostingFailedException('AMOUNT_NOT_INTEGER', "Expression '{$expression}' produced non-integer; amounts must be minor units");
        }
        return $v;
    }

    /** Basis points on a minor-unit base, banker's rounding. 1500bp = 15%. */
    public static function pct(int $base, int $bp): int
    {
        $num = $base * $bp;             // exact in int for realistic magnitudes (< 9e18)
        $q = intdiv($num, 10_000);
        $r = $num % 10_000;
        if (abs($r) * 2 > 10_000 || (abs($r) * 2 === 10_000 && $q % 2 !== 0)) {
            $q += $num >= 0 ? 1 : -1;
        }
        return $q;
    }
}
