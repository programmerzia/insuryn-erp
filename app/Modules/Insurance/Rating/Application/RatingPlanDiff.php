<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Rating\Domain\Definition\RateRow;
use App\Modules\Insurance\Rating\Domain\Definition\RateTableDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingStepDefinition;
use App\Modules\Insurance\Rating\Domain\Enums\RateValueType;

/**
 * Phase 3 design §6 tariff editor "diff view" (slice R10a): what changed from one version of a plan to another. Pure — two definitions in, a plain
 * array out; the values stay in their stored units (D-20) and the screen formats them.
 * - Header: name, class, effective dates, source and currency that differ.
 * - Tables by code: added or removed (with all their rows), or changed (name, dimensions, value type, rows). A row is "the same row" in both versions
 *   when its keys (a band table: its band start) and its own start date match; it is changed when its value, band end, band label or end date differ.
 *   Two rows with the same identity in one version (a draft may have them) are paired in order.
 * - Steps by code: added, removed, or changed with the fields that differ.
 */
final class RatingPlanDiff
{
    private const HEADER_FIELDS = ['name', 'class_code', 'effective_from', 'effective_to', 'source', 'currency'];

    private const ROW_FIELDS = ['value_bp', 'value_minor', 'band_to', 'band_label', 'effective_to'];

    private const STEP_FIELDS = ['order_no', 'kind', 'expression', 'condition', 'applies_to', 'label_en', 'label_bn'];

    /**
     * @return array{
     *     identical: bool,
     *     header: list<array{field: string, before: mixed, after: mixed}>,
     *     tables: list<array{code: string, name: string, value_type: string, dimensions: list<string>, status: string, changes: list<array{field: string, before: mixed, after: mixed}>,
     *         rows: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{before: array<string, mixed>, after: array<string, mixed>, fields: list<string>}>}}>,
     *     steps: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{code: string, changes: list<array{field: string, before: mixed, after: mixed}>}>}
     * }
     */
    public function compare(RatingPlanDefinition $from, RatingPlanDefinition $to): array
    {
        $header = $this->changes(array_intersect_key($from->toArray(), array_flip(self::HEADER_FIELDS)), array_intersect_key($to->toArray(), array_flip(self::HEADER_FIELDS)), self::HEADER_FIELDS);
        $tables = $this->tables($from->tablesByCode(), $to->tablesByCode());
        $steps = $this->steps($from->steps, $to->steps);

        return [
            'identical' => $header === [] && $tables === [] && $steps['added'] === [] && $steps['removed'] === [] && $steps['changed'] === [],
            'header' => $header,
            'tables' => $tables,
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string, RateTableDefinition> $before
     * @param array<string, RateTableDefinition> $after
     * @return list<array{code: string, name: string, value_type: string, dimensions: list<string>, status: string, changes: list<array{field: string, before: mixed, after: mixed}>,
     *     rows: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{before: array<string, mixed>, after: array<string, mixed>, fields: list<string>}>}}>
     */
    private function tables(array $before, array $after): array
    {
        $codes = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        sort($codes);
        $result = [];
        foreach ($codes as $code) {
            $old = $before[$code] ?? null;
            $new = $after[$code] ?? null;
            $shown = $new ?? $old;
            if ($shown === null) {
                continue;
            }
            if ($old === null) {
                $result[] = $this->describe($shown, 'added', [], ['added' => $this->rowArrays($shown->rows), 'removed' => [], 'changed' => []]);
                continue;
            }
            if ($new === null) {
                $result[] = $this->describe($shown, 'removed', [], ['added' => [], 'removed' => $this->rowArrays($shown->rows), 'changed' => []]);
                continue;
            }
            $changes = $this->changes(['name' => $old->name, 'dimensions' => $old->dimensions, 'value_type' => $old->valueType->value],
                ['name' => $new->name, 'dimensions' => $new->dimensions, 'value_type' => $new->valueType->value], ['name', 'dimensions', 'value_type']);
            $rows = $this->rows($old, $new);
            if ($changes !== [] || $rows['added'] !== [] || $rows['removed'] !== [] || $rows['changed'] !== []) {
                $result[] = $this->describe($shown, 'changed', $changes, $rows);
            }
        }

        return $result;
    }

    /** @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{before: array<string, mixed>, after: array<string, mixed>, fields: list<string>}>} */
    private function rows(RateTableDefinition $old, RateTableDefinition $new): array
    {
        $before = $this->byIdentity($old);
        $after = $this->byIdentity($new);
        $added = [];
        $changed = [];
        foreach ($after as $identity => $row) {
            if (! isset($before[$identity])) {
                $added[] = $row->toArray();
                continue;
            }
            $fields = array_column($this->changes($before[$identity]->toArray(), $row->toArray(), self::ROW_FIELDS), 'field');
            if ($fields !== []) {
                $changed[] = ['before' => $before[$identity]->toArray(), 'after' => $row->toArray(), 'fields' => $fields];
            }
        }
        $removed = array_values(array_map(fn (RateRow $row): array => $row->toArray(), array_diff_key($before, $after)));

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /** @return array<string, RateRow> rows keyed by identity, in table order */
    private function byIdentity(RateTableDefinition $table): array
    {
        $rows = [];
        foreach ($table->rows as $row) {
            $base = json_encode([$table->valueType === RateValueType::Band ? $row->bandFrom : $row->keys, $row->effectiveFrom], JSON_THROW_ON_ERROR);
            $identity = $base;
            for ($n = 2; isset($rows[$identity]); $n++) {
                $identity = "{$base}#{$n}";
            }
            $rows[$identity] = $row;
        }

        return $rows;
    }

    /**
     * @param list<RatingStepDefinition> $before
     * @param list<RatingStepDefinition> $after
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{code: string, changes: list<array{field: string, before: mixed, after: mixed}>}>}
     */
    private function steps(array $before, array $after): array
    {
        $old = [];
        foreach ($before as $step) {
            $old[$step->code] = $step->toArray();
        }
        $added = [];
        $changed = [];
        $seen = [];
        foreach ($after as $step) {
            $seen[$step->code] = true;
            $new = $step->toArray();
            if (! isset($old[$step->code])) {
                $added[] = $new;
                continue;
            }
            $changes = $this->changes($old[$step->code], $new, self::STEP_FIELDS);
            if ($changes !== []) {
                $changed[] = ['code' => $step->code, 'changes' => $changes];
            }
        }

        return ['added' => $added, 'removed' => array_values(array_diff_key($old, $seen)), 'changed' => $changed];
    }

    /**
     * @param list<array{field: string, before: mixed, after: mixed}> $changes
     * @param array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{before: array<string, mixed>, after: array<string, mixed>, fields: list<string>}>} $rows
     * @return array{code: string, name: string, value_type: string, dimensions: list<string>, status: string, changes: list<array{field: string, before: mixed, after: mixed}>,
     *     rows: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{before: array<string, mixed>, after: array<string, mixed>, fields: list<string>}>}}
     */
    private function describe(RateTableDefinition $table, string $status, array $changes, array $rows): array
    {
        return ['code' => $table->code, 'name' => $table->name, 'value_type' => $table->valueType->value, 'dimensions' => $table->dimensions, 'status' => $status,
            'changes' => $changes, 'rows' => $rows];
    }

    /**
     * @param list<RateRow> $rows
     * @return list<array<string, mixed>>
     */
    private function rowArrays(array $rows): array
    {
        return array_map(fn (RateRow $row): array => $row->toArray(), $rows);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $fields
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    private function changes(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['field' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }

        return $changes;
    }
}
