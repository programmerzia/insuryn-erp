<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Risk;

use App\Modules\Insurance\Product\Domain\Enums\RiskFieldType;
use App\Modules\Insurance\Product\Domain\Enums\RiskStage;

/**
 * One field of a product version's risk schema (Phase 3 design §1): what the officer captures on the quote form (R4), in English and Bangla.
 * `options` only for select fields; `min`/`max` only for integer and money fields (money in minor units); `max_length` only for text.
 * Flow fix X7: `required_at` — a required field is needed to rate (`quote`, the default) or only when the proposal is submitted (`proposal`); `default` — the
 * value the quote form starts with (a valid value of the field, normalised), never applied by the server.
 */
final readonly class RiskField
{
    /** @param list<array{value: string, label_en: string, label_bn: string}> $options */
    public function __construct(
        public string $key,
        public string $labelEn,
        public string $labelBn,
        public RiskFieldType $type,
        public bool $required,
        public array $options = [],
        public ?int $min = null,
        public ?int $max = null,
        public ?int $maxLength = null,
        public RiskStage $requiredAt = RiskStage::Quote,
        public int|string|bool|null $default = null,
    ) {}

    /** Whether the field must hold a value at the stage: a quote-stage field at every stage, a proposal-stage field only at the proposal. */
    public function requiredFor(RiskStage $stage): bool
    {
        return $this->required && ($this->requiredAt === RiskStage::Quote || $stage === RiskStage::Proposal);
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        return array_column($this->options, 'value');
    }

    /** @return array{key: string, label_en: string, label_bn: string, type: string, required: bool, options?: list<array{value: string, label_en: string, label_bn: string}>, min?: int, max?: int, max_length?: int, required_at?: string, default?: int|string|bool} */
    public function toArray(): array
    {
        $field = ['key' => $this->key, 'label_en' => $this->labelEn, 'label_bn' => $this->labelBn, 'type' => $this->type->value, 'required' => $this->required];
        if ($this->type === RiskFieldType::Select) {
            $field['options'] = $this->options;
        }
        if ($this->min !== null) {
            $field['min'] = $this->min;
        }
        if ($this->max !== null) {
            $field['max'] = $this->max;
        }
        if ($this->maxLength !== null) {
            $field['max_length'] = $this->maxLength;
        }
        if ($this->requiredAt !== RiskStage::Quote) {
            $field['required_at'] = $this->requiredAt->value;
        }
        if ($this->default !== null) {
            $field['default'] = $this->default;
        }

        return $field;
    }
}
