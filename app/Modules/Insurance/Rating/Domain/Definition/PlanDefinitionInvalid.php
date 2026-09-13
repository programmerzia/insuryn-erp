<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

use App\Modules\Insurance\Rating\Domain\RatingFailed;

/** Factory for RATING_PLAN_INVALID failures: a plan, table, row, step or duty that is not well formed. */
final class PlanDefinitionInvalid
{
    public static function because(string $message): RatingFailed
    {
        return new RatingFailed('RATING_PLAN_INVALID', $message);
    }
}
