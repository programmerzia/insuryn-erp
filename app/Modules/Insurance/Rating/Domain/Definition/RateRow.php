<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

/**
 * One row of a rate table (Phase 3 design §1 rate_table_rows). `keys` maps each table dimension to its value (compared as strings). Band rows cover
 * the half-open range [band_from, band_to) — band_to null = no upper end. A row's own effective dates narrow the plan's (null = the plan's).
 */
final readonly class RateRow
{
    /** @param array<string, string> $keys */
    public function __construct(
        public array $keys,
        public ?int $valueMinor = null,
        public ?int $valueBp = null,
        public ?int $bandFrom = null,
        public ?int $bandTo = null,
        public ?string $bandLabel = null,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
    ) {}

    /** In force on $day (Y-m-d)? */
    public function inForceOn(string $day): bool
    {
        return ($this->effectiveFrom === null || $this->effectiveFrom <= $day) && ($this->effectiveTo === null || $day < $this->effectiveTo);
    }

    public function contains(int $value): bool
    {
        return $this->bandFrom !== null && $this->bandFrom <= $value && ($this->bandTo === null || $value < $this->bandTo);
    }

    /** The row's value: value_bp for rate tables, value_minor for flat ones (band rows may carry either). */
    public function value(): ?int
    {
        return $this->valueBp ?? $this->valueMinor;
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): self
    {
        $keys = $row['keys'] ?? [];
        if (! is_array($keys)) {
            throw PlanDefinitionInvalid::because('Rate row keys must be an object of dimension → value.');
        }
        $normalised = [];
        foreach ($keys as $dimension => $value) {
            if (! is_string($dimension) || ! (is_string($value) || is_int($value))) {
                throw PlanDefinitionInvalid::because('Rate row keys must be an object of dimension → value.');
            }
            $normalised[$dimension] = (string) $value;
        }
        ksort($normalised);

        return new self($normalised, self::optionalInt($row, 'value_minor'), self::optionalInt($row, 'value_bp'), self::optionalInt($row, 'band_from'),
            self::optionalInt($row, 'band_to'), self::optionalString($row, 'band_label'), self::optionalString($row, 'effective_from'), self::optionalString($row, 'effective_to'));
    }

    /** @return array{keys: array<string, string>, value_minor: int|null, value_bp: int|null, band_from: int|null, band_to: int|null, band_label: string|null, effective_from: string|null, effective_to: string|null} */
    public function toArray(): array
    {
        return ['keys' => $this->keys, 'value_minor' => $this->valueMinor, 'value_bp' => $this->valueBp, 'band_from' => $this->bandFrom, 'band_to' => $this->bandTo,
            'band_label' => $this->bandLabel, 'effective_from' => $this->effectiveFrom, 'effective_to' => $this->effectiveTo];
    }

    /** @param array<mixed> $row */
    private static function optionalInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw PlanDefinitionInvalid::because("Rate row {$key} must be an integer (no decimals: rates are basis points, amounts minor units).");
        }

        return $value;
    }

    /** @param array<mixed> $row */
    private static function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw PlanDefinitionInvalid::because("Rate row {$key} must be text.");
        }

        return $value;
    }
}
