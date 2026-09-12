<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

/** Outcome of one statement file: new lines, lines already imported before, and row errors (file line → message; nothing imported when any). */
final readonly class StatementImportResult
{
    /** @param array<int, string> $errors */
    public function __construct(
        public int $imported,
        public int $duplicates,
        public array $errors = [],
    ) {}
}
