<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Expressions\ExpressionScope;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Design §3.2: tiny safe expression language over payload.* and dims.* in MINOR UNITS (int).
 * Functions (see PostingFunctionProvider): pct(base, bp) half-even, min, max, add, sub.
 */
final class AmountEvaluator
{
    public function __construct(private readonly ExpressionLanguage $expressions) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     */
    public function evaluate(string $expression, array $payload, array $dimensions): int
    {
        $amount = $this->expressions->evaluate($expression, ExpressionScope::forEvent($payload, $dimensions));
        if (! is_int($amount)) {
            throw new PostingFailedException('AMOUNT_NOT_INTEGER', "Expression '{$expression}' produced ".get_debug_type($amount).'; amounts must be integer minor units');
        }

        return $amount;
    }
}
