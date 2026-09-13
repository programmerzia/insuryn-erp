<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Expressions;

use App\Modules\Insurance\Rating\Domain\Definition\RateTableDefinition;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use Closure;

/**
 * What the rating functions need besides their arguments: the plan's rate tables, the rating date and how to work out a duty. Passed to the
 * expression language as a hidden variable that expressions themselves cannot name.
 */
final readonly class RatingContext
{
    public const VARIABLE = '__rating';

    /**
     * @param array<string, RateTableDefinition> $tables by code
     * @param Closure(string): int $duty duty code → amount on the current net premium
     */
    public function __construct(
        private array $tables,
        public string $day,
        private Closure $duty,
    ) {}

    public function table(string $code): RateTableDefinition
    {
        return $this->tables[$code] ?? throw new RatingFailed('RATE_TABLE_UNKNOWN', "The plan has no rate table {$code}.");
    }

    public function duty(string $code): int
    {
        return ($this->duty)($code);
    }
}
