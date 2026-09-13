<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

use App\Modules\Insurance\Rating\Domain\Enums\RatingStepKind;

/**
 * A rating step (Phase 3 design §1 rating_steps): runs in `order_no`; `expression` gives the step's amount in minor units, `condition` (optional)
 * decides whether it runs, `applies_to` names the coverage it rates (the step runs only when that coverage is on the quote). Labels in English
 * and Bangla explain the step on the breakdown.
 */
final readonly class RatingStepDefinition
{
    public function __construct(
        public int $orderNo,
        public string $code,
        public RatingStepKind $kind,
        public string $expression,
        public ?string $condition,
        public ?string $appliesTo,
        public string $labelEn,
        public string $labelBn,
    ) {}

    /** @param array<mixed> $step */
    public static function fromArray(array $step): self
    {
        $kind = is_string($step['kind'] ?? null) ? RatingStepKind::tryFrom($step['kind']) : null;
        $code = $step['code'] ?? null;
        $condition = $step['condition'] ?? null;
        $appliesTo = $step['applies_to'] ?? null;
        if (! is_int($step['order_no'] ?? null) || ! is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1 || $kind === null
            || ! is_string($step['expression'] ?? null) || trim($step['expression']) === '' || ! is_string($step['label_en'] ?? null) || ! is_string($step['label_bn'] ?? null)
            || ($condition !== null && ! is_string($condition)) || ($appliesTo !== null && ! is_string($appliesTo))) {
            throw PlanDefinitionInvalid::because('A rating step needs an integer order_no, a snake_case code, a kind (base, coverage, loading, discount, minimum, rounding, duty, tax), '
                .'an expression, label_en and label_bn; condition and applies_to are optional text.');
        }

        return new self($step['order_no'], $code, $kind, $step['expression'], $condition === '' ? null : $condition, $appliesTo === '' ? null : $appliesTo,
            $step['label_en'], $step['label_bn']);
    }

    /** @return array{order_no: int, code: string, kind: string, expression: string, condition: string|null, applies_to: string|null, label_en: string, label_bn: string} */
    public function toArray(): array
    {
        return ['order_no' => $this->orderNo, 'code' => $this->code, 'kind' => $this->kind->value, 'expression' => $this->expression, 'condition' => $this->condition,
            'applies_to' => $this->appliesTo, 'label_en' => $this->labelEn, 'label_bn' => $this->labelBn];
    }
}
