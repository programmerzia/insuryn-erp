<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Imports;

/**
 * Result of one import request. Row 0 marks file-level problems (e.g. the file does not balance); they are
 * listed after row errors. `result` is set only when a commit happened.
 */
final class ImportOutcome
{
    /** @var list<array{row: int, field: string, message: string}> */
    private array $errors = [];

    /** @var array<string, mixed> */
    public array $preview = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function __construct(
        public readonly string $type,
        public readonly ImportMode $mode,
    ) {}

    public function addError(int $row, string $field, string $message): void
    {
        $this->errors[] = ['row' => $row, 'field' => $field, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function rowHasErrors(int $row): bool
    {
        return array_filter($this->errors, fn (array $e): bool => $e['row'] === $row) !== [];
    }

    /** @return array{type: string, mode: string, valid: bool, errors: list<array{row: int, field: string, message: string}>, preview: array<string, mixed>, result: array<string, mixed>|null} */
    public function toArray(): array
    {
        $errors = $this->errors;
        usort($errors, fn (array $a, array $b): int => [$a['row'] === 0 ? PHP_INT_MAX : $a['row'], $a['field']] <=> [$b['row'] === 0 ? PHP_INT_MAX : $b['row'], $b['field']]);

        return ['type' => $this->type, 'mode' => $this->mode->value, 'valid' => ! $this->hasErrors(), 'errors' => $errors,
            'preview' => $this->hasErrors() ? [] : $this->preview, 'result' => $this->result];
    }
}
