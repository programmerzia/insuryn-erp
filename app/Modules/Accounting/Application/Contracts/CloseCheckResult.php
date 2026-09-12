<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

/** Outcome of a close task: passed → done, otherwise blocked. Details are stored in period_close_tasks.result. */
final readonly class CloseCheckResult
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public bool $passed,
        public string $summary,
        public array $details = [],
    ) {}

    /** @param array<string, mixed> $details */
    public static function passed(string $summary, array $details = []): self
    {
        return new self(true, $summary, $details);
    }

    /** @param array<string, mixed> $details */
    public static function blocked(string $summary, array $details = []): self
    {
        return new self(false, $summary, $details);
    }
}
