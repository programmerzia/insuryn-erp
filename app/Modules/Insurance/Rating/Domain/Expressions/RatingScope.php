<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Expressions;

use App\Modules\Insurance\Rating\Domain\RatingFailed;

/**
 * Read-only view of rating data for step expressions (`risk.engine_cc`, `coverage.code`, `running.premium`, `steps.base`), as the posting-rule
 * ExpressionScope does for events. Reading a field that does not exist fails with a clear reason; `risk.ncb_years ?? 0` reads an optional field.
 */
final readonly class RatingScope
{
    /** @param array<string, mixed> $fields */
    public function __construct(private string $path, private array $fields) {}

    public function __get(string $field): mixed
    {
        if (! array_key_exists($field, $this->fields)) {
            throw new RatingFailed($this->path === 'risk' ? 'RISK_INPUT_MISSING' : 'RATING_EXPRESSION_INVALID',
                "A rating expression reads '{$this->path}.{$field}', which does not exist.");
        }

        return $this->fields[$field];
    }

    public function __isset(string $field): bool
    {
        return isset($this->fields[$field]);
    }
}
