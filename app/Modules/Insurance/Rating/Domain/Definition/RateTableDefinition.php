<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

use App\Modules\Insurance\Rating\Domain\Enums\RateValueType;
use App\Modules\Insurance\Rating\Domain\RatingFailed;

/**
 * A rate table of a plan (Phase 3 design §1 rate_tables): dimensions in order, the value type (units: D-20) and the rows.
 * `lookup` finds the one row matching the keys in force on the day; `band` finds the band row containing a value.
 */
final readonly class RateTableDefinition
{
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param list<string> $dimensions
     * @param list<RateRow> $rows
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $dimensions,
        public RateValueType $valueType,
        public array $rows,
    ) {}

    /** @param list<string|int> $keys positional, in dimension order */
    public function lookup(array $keys, string $day): int
    {
        if ($this->valueType === RateValueType::Band) {
            throw new RatingFailed('RATE_TABLE_TYPE', "Table {$this->code} is a band table: use band() or band_value().");
        }
        if (count($keys) !== count($this->dimensions)) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', "Table {$this->code} has ".count($this->dimensions).' dimension(s) ('.implode(', ', $this->dimensions).'); lookup gave '.count($keys).'.');
        }
        $wanted = [];
        foreach ($this->dimensions as $i => $dimension) {
            $wanted[$dimension] = (string) $keys[$i];
        }
        ksort($wanted);
        $matches = array_values(array_filter($this->rows, fn (RateRow $row): bool => $row->keys === $wanted && $row->inForceOn($day)));
        $described = implode(', ', array_map(fn (string $d, string $v): string => "{$d}={$v}", array_keys($wanted), $wanted));
        if ($matches === []) {
            throw new RatingFailed('RATE_NOT_FOUND', "No rate in table {$this->code} for {$described} on {$day}.");
        }
        if (count($matches) > 1) {
            throw new RatingFailed('RATE_AMBIGUOUS', "Table {$this->code} has more than one rate for {$described} on {$day}.");
        }

        return $matches[0]->value() ?? throw new RatingFailed('RATE_NOT_FOUND', "The rate in table {$this->code} for {$described} has no value.");
    }

    public function band(int $value, string $day): RateRow
    {
        if ($this->valueType !== RateValueType::Band) {
            throw new RatingFailed('RATE_TABLE_TYPE', "Table {$this->code} is not a band table.");
        }
        $matches = array_values(array_filter($this->rows, fn (RateRow $row): bool => $row->contains($value) && $row->inForceOn($day)));
        if ($matches === []) {
            throw new RatingFailed('BAND_NOT_FOUND', "No band in table {$this->code} contains {$value} on {$day}.");
        }
        if (count($matches) > 1) {
            throw new RatingFailed('RATE_AMBIGUOUS', "More than one band in table {$this->code} contains {$value} on {$day}.");
        }

        return $matches[0];
    }

    /** @return list<string> problems that stop the plan being approved */
    public function problems(): array
    {
        $problems = [];
        if (preg_match(self::CODE_PATTERN, $this->code) !== 1) {
            $problems[] = "Table code {$this->code} must be snake_case.";
        }
        if ($this->valueType !== RateValueType::Band && $this->dimensions === []) {
            $problems[] = "Table {$this->code} needs at least one dimension.";
        }
        if ($this->valueType === RateValueType::Band && $this->dimensions !== []) {
            $problems[] = "Band table {$this->code} has no dimensions: its rows are ranges.";
        }
        if ($this->rows === []) {
            $problems[] = "Table {$this->code} has no rows.";
        }
        $dimensions = $this->dimensions;
        sort($dimensions);
        foreach ($this->rows as $i => $row) {
            $at = "Table {$this->code} row ".($i + 1);
            if (array_keys($row->keys) !== $dimensions) {
                $problems[] = "{$at}: keys must be exactly ".($dimensions === [] ? 'none' : implode(', ', $dimensions)).'.';
            }
            if ($row->effectiveFrom !== null && $row->effectiveTo !== null && $row->effectiveTo <= $row->effectiveFrom) {
                $problems[] = "{$at}: ends before it starts.";
            }
            $problems = [...$problems, ...$this->valueProblems($row, $at)];
        }

        return [...$problems, ...$this->overlapProblems()];
    }

    /** @return list<string> */
    private function valueProblems(RateRow $row, string $at): array
    {
        return match ($this->valueType) {
            RateValueType::RatePct, RateValueType::RatePerMille => $row->valueBp === null || $row->valueMinor !== null || $row->bandFrom !== null
                ? ["{$at}: a rate table row has value_bp only."] : ($row->valueBp < 0 ? ["{$at}: a rate cannot be negative."] : []),
            RateValueType::Flat => $row->valueMinor === null || $row->valueBp !== null || $row->bandFrom !== null
                ? ["{$at}: a flat table row has value_minor only."] : ($row->valueMinor < 0 ? ["{$at}: an amount cannot be negative."] : []),
            RateValueType::Band => $row->bandFrom === null || $row->bandLabel === null || ($row->bandTo !== null && $row->bandTo <= $row->bandFrom)
                || ($row->valueBp !== null && $row->valueMinor !== null)
                ? ["{$at}: a band row has band_from < band_to (or no upper end), a band_label and at most one value."] : [],
        };
    }

    /** @return list<string> */
    private function overlapProblems(): array
    {
        $problems = [];
        $rows = $this->rows;
        foreach ($rows as $i => $a) {
            foreach (array_slice($rows, $i + 1) as $b) {
                $datesOverlap = ($a->effectiveTo === null || $b->effectiveFrom === null || $b->effectiveFrom < $a->effectiveTo)
                    && ($b->effectiveTo === null || $a->effectiveFrom === null || $a->effectiveFrom < $b->effectiveTo);
                if (! $datesOverlap) {
                    continue;
                }
                if ($this->valueType === RateValueType::Band) {
                    if ($a->bandFrom !== null && $b->bandFrom !== null && ($a->bandTo === null || $b->bandFrom < $a->bandTo) && ($b->bandTo === null || $a->bandFrom < $b->bandTo)) {
                        $problems[] = "Table {$this->code}: bands {$a->bandLabel} and {$b->bandLabel} overlap.";
                    }
                } elseif ($a->keys === $b->keys) {
                    $problems[] = "Table {$this->code}: two rows for ".json_encode($a->keys, JSON_UNESCAPED_UNICODE).' are in force at the same time.';
                }
            }
        }

        return $problems;
    }

    /** @param array<mixed> $table */
    public static function fromArray(array $table): self
    {
        $code = $table['code'] ?? null;
        $name = $table['name'] ?? $code;
        $dimensions = $table['dimensions'] ?? [];
        $type = is_string($table['value_type'] ?? null) ? RateValueType::tryFrom($table['value_type']) : null;
        if (! is_string($code) || ! is_string($name) || $type === null || ! is_array($dimensions) || ! array_is_list($dimensions)
            || array_filter($dimensions, fn (mixed $d): bool => ! is_string($d) || preg_match(self::CODE_PATTERN, $d) !== 1) !== [] || count(array_unique($dimensions)) !== count($dimensions)) {
            throw PlanDefinitionInvalid::because('A rate table needs a code, a name, a list of distinct snake_case dimensions and a value_type of rate_pm, rate_pct, flat or band.');
        }
        $rows = $table['rows'] ?? [];
        if (! is_array($rows)) {
            throw PlanDefinitionInvalid::because("Table {$code}: rows must be a list.");
        }

        return new self($code, $name, array_map(fn (mixed $d): string => is_string($d) ? $d : '', $dimensions), $type, array_values(array_map(function (mixed $row) use ($code): RateRow {
            if (! is_array($row)) {
                throw PlanDefinitionInvalid::because("Table {$code}: each row must be an object.");
            }

            return RateRow::fromArray($row);
        }, $rows)));
    }

    /** @return array{code: string, name: string, dimensions: list<string>, value_type: string, rows: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'dimensions' => $this->dimensions, 'value_type' => $this->valueType->value,
            'rows' => array_map(fn (RateRow $row): array => $row->toArray(), $this->rows)];
    }
}
