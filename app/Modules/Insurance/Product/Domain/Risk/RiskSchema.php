<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Risk;

use App\Modules\Insurance\Product\Domain\Enums\RiskFieldType;
use DateTimeImmutable;

/**
 * Phase 3 design §1 product_versions.risk_schema: the ordered list of risk fields a product version needs (vehicle, occupancy, voyage…).
 *
 * Stored shape (JSON list): `{key, label_en, label_bn, type: text|integer|money|date|select|boolean, required, options?: [{value, label_en, label_bn}],
 * min?, max?, max_length?}`. Keys are snake_case and unique. `validate()` checks a set of inputs against the schema and returns them normalised
 * (integers and money as int minor units, booleans as bool, dates as Y-m-d, absent optional fields as null) in schema order. No floats.
 */
final readonly class RiskSchema
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private const DEFAULT_TEXT_LENGTH = 255;

    /** @param list<RiskField> $fields */
    private function __construct(public array $fields) {}

    /**
     * @param array<mixed> $definition
     *
     * @throws RiskSchemaInvalid naming the first problem
     */
    public static function fromArray(array $definition): self
    {
        if (! array_is_list($definition)) {
            throw new RiskSchemaInvalid('A risk schema is a list of fields.');
        }
        $fields = [];
        $keys = [];
        foreach ($definition as $position => $field) {
            if (! is_array($field)) {
                throw new RiskSchemaInvalid("Risk field #{$position} is not an object.");
            }
            $parsed = self::parseField($field, $position);
            if (in_array($parsed->key, $keys, true)) {
                throw new RiskSchemaInvalid("Risk field key {$parsed->key} appears twice.");
            }
            $keys[] = $parsed->key;
            $fields[] = $parsed;
        }

        return new self($fields);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function field(string $key): ?RiskField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (RiskField $field): array => $field->toArray(), $this->fields);
    }

    /**
     * @param array<mixed> $inputs field key → value
     * @return array<string, int|string|bool|null> normalised values in schema order
     *
     * @throws RiskInputsInvalid listing every problem
     */
    public function validate(array $inputs): array
    {
        $errors = [];
        foreach (array_keys($inputs) as $key) {
            if ($this->field((string) $key) === null) {
                $errors[(string) $key] = 'UNKNOWN_FIELD';
            }
        }
        $values = [];
        foreach ($this->fields as $field) {
            $raw = $inputs[$field->key] ?? null;
            if ($raw === null || $raw === '') {
                if ($field->required) {
                    $errors[$field->key] = 'REQUIRED';
                }
                $values[$field->key] = null;

                continue;
            }
            [$value, $problem] = self::normalise($field, $raw);
            if ($problem !== null) {
                $errors[$field->key] = $problem;
            }
            $values[$field->key] = $value;
        }
        if ($errors !== []) {
            throw new RiskInputsInvalid($errors);
        }

        return $values;
    }

    /** @return array{0: int|string|bool|null, 1: string|null} value and problem code */
    private static function normalise(RiskField $field, mixed $raw): array
    {
        switch ($field->type) {
            case RiskFieldType::Text:
                if (! is_string($raw)) {
                    return [null, 'NOT_TEXT'];
                }

                return mb_strlen($raw) > ($field->maxLength ?? self::DEFAULT_TEXT_LENGTH) ? [null, 'TOO_LONG'] : [$raw, null];
            case RiskFieldType::Integer:
            case RiskFieldType::Money:
                $int = self::integer($raw);
                if ($int === null) {
                    return [null, 'NOT_INTEGER'];
                }
                $min = $field->min ?? ($field->type === RiskFieldType::Money ? 0 : null);
                if ($min !== null && $int < $min) {
                    return [$int, 'BELOW_MIN'];
                }

                return $field->max !== null && $int > $field->max ? [$int, 'ABOVE_MAX'] : [$int, null];
            case RiskFieldType::Date:
                if (! is_string($raw)) {
                    return [null, 'NOT_A_DATE'];
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

                return $date === false || $date->format('Y-m-d') !== $raw ? [null, 'NOT_A_DATE'] : [$raw, null];
            case RiskFieldType::Select:
                return is_string($raw) && in_array($raw, $field->optionValues(), true) ? [$raw, null] : [null, 'NOT_AN_OPTION'];
            case RiskFieldType::Boolean:
                return match (true) {
                    is_bool($raw) => [$raw, null],
                    in_array($raw, [1, '1', 'true'], true) => [true, null],
                    in_array($raw, [0, '0', 'false'], true) => [false, null],
                    default => [null, 'NOT_BOOLEAN'],
                };
        }
    }

    /** An int, or a string of digits with an optional leading minus; never a float. */
    private static function integer(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d{1,18}$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }

    /** @param array<mixed> $field */
    private static function parseField(array $field, int $position): RiskField
    {
        $unknown = array_diff(array_keys($field), ['key', 'label_en', 'label_bn', 'type', 'required', 'options', 'min', 'max', 'max_length']);
        if ($unknown !== []) {
            throw new RiskSchemaInvalid("Risk field #{$position} has unknown settings: ".implode(', ', $unknown).'.');
        }
        $key = $field['key'] ?? null;
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RiskSchemaInvalid("Risk field #{$position} needs a snake_case key.");
        }
        foreach (['label_en', 'label_bn'] as $label) {
            if (! is_string($field[$label] ?? null) || trim($field[$label]) === '') {
                throw new RiskSchemaInvalid("Risk field {$key} needs {$label}.");
            }
        }
        $type = is_string($field['type'] ?? null) ? RiskFieldType::tryFrom($field['type']) : null;
        if ($type === null) {
            throw new RiskSchemaInvalid("Risk field {$key} has no valid type (text, integer, money, date, select, boolean).");
        }
        $required = $field['required'] ?? false;
        if (! is_bool($required)) {
            throw new RiskSchemaInvalid("Risk field {$key}: required must be true or false.");
        }

        $options = [];
        if ($type === RiskFieldType::Select) {
            $options = self::options($key, $field['options'] ?? null);
        } elseif (array_key_exists('options', $field)) {
            throw new RiskSchemaInvalid("Risk field {$key}: only select fields have options.");
        }

        foreach (['min', 'max'] as $bound) {
            if (array_key_exists($bound, $field) && (! $type->isNumeric() || ! is_int($field[$bound]))) {
                throw new RiskSchemaInvalid("Risk field {$key}: {$bound} must be an integer and only integer or money fields have it.");
            }
        }
        $min = isset($field['min']) && is_int($field['min']) ? $field['min'] : null;
        $max = isset($field['max']) && is_int($field['max']) ? $field['max'] : null;
        if ($min !== null && $max !== null && $min > $max) {
            throw new RiskSchemaInvalid("Risk field {$key}: min is above max.");
        }
        if ($type === RiskFieldType::Money && $min !== null && $min < 0) {
            throw new RiskSchemaInvalid("Risk field {$key}: a money field cannot go below zero.");
        }
        $maxLength = null;
        if (array_key_exists('max_length', $field)) {
            if ($type !== RiskFieldType::Text || ! is_int($field['max_length']) || $field['max_length'] < 1) {
                throw new RiskSchemaInvalid("Risk field {$key}: max_length must be a positive integer and only text fields have it.");
            }
            $maxLength = $field['max_length'];
        }

        return new RiskField($key, $field['label_en'], $field['label_bn'], $type, $required, $options, $min, $max, $maxLength);
    }

    /** @return list<array{value: string, label_en: string, label_bn: string}> */
    private static function options(string $key, mixed $options): array
    {
        if (! is_array($options) || $options === [] || ! array_is_list($options)) {
            throw new RiskSchemaInvalid("Risk field {$key}: a select field needs a list of options.");
        }
        $parsed = [];
        foreach ($options as $option) {
            if (! is_array($option) || ! is_string($option['value'] ?? null) || $option['value'] === ''
                || ! is_string($option['label_en'] ?? null) || ! is_string($option['label_bn'] ?? null)
                || array_diff(array_keys($option), ['value', 'label_en', 'label_bn']) !== []) {
                throw new RiskSchemaInvalid("Risk field {$key}: each option needs value, label_en and label_bn.");
            }
            if (in_array($option['value'], array_column($parsed, 'value'), true)) {
                throw new RiskSchemaInvalid("Risk field {$key}: option {$option['value']} appears twice.");
            }
            $parsed[] = ['value' => $option['value'], 'label_en' => $option['label_en'], 'label_bn' => $option['label_bn']];
        }

        return $parsed;
    }
}
