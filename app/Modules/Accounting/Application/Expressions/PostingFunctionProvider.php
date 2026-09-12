<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Expressions;

use App\Modules\Accounting\Domain\BasisPoints;
use LogicException;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

/**
 * Integer functions for posting-rule expressions (design §3.2), registered once when the expression
 * language is constructed. `min` and `max` are ExpressionLanguage built-ins.
 * Rules are evaluated, never compiled to PHP.
 */
final class PostingFunctionProvider implements ExpressionFunctionProviderInterface
{
    /** @return list<ExpressionFunction> */
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction('pct', self::notCompilable(...),
                static fn (array $variables, int $minorUnits, int $basisPoints): int => BasisPoints::of($minorUnits, $basisPoints)),
            new ExpressionFunction('add', self::notCompilable(...),
                static fn (array $variables, int $left, int $right): int => $left + $right),
            new ExpressionFunction('sub', self::notCompilable(...),
                static fn (array $variables, int $left, int $right): int => $left - $right),
        ];
    }

    private static function notCompilable(): never
    {
        throw new LogicException('Posting-rule expressions are evaluated, not compiled.');
    }
}
