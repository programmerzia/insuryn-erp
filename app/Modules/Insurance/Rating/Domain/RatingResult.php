<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

use InvalidArgumentException;

/**
 * The outcome of rating (Phase 3 design §1 step 8 and INVARIANT "every result stores the tariff version and the full breakdown"): net premium,
 * per-coverage premiums, loadings, discounts, minimum and rounding adjustments, duties and gross, in integer minor units, with the plan version,
 * the product version, the rating date, a hash of the inputs and an explanation line (EN/BN) per step. `toArray()` / `fromArray()` round-trip
 * exactly, so a quotation or policy can freeze it as JSON (R4, R7) and re-explain the premium years later.
 *
 * @phpstan-type Line array{code: string, label_en: string, label_bn: string, amount_minor: int}
 * @phpstan-type Explanation array{step_code: string, kind: string, label_en: string, label_bn: string, amount_minor: int, running_total_minor: int}
 */
final readonly class RatingResult
{
    /**
     * @param array{id: string|null, code: string, version: int, class_code: string} $plan
     * @param array<string, int|string|bool|null> $riskInputs normalised, in risk schema order
     * @param list<string> $coverages the coverages rated (mandatory and chosen)
     * @param list<array{code: string, amount_minor: int}> $coveragePremiums
     * @param list<Line> $loadings
     * @param list<Line> $discounts
     * @param list<Line> $duties
     * @param list<Explanation> $explanation
     */
    public function __construct(
        public string $currency,
        public string $asOf,
        public ?string $productVersionId,
        public array $plan,
        public string $inputsHash,
        public array $riskInputs,
        public array $coverages,
        public int $basePremiumMinor,
        public array $coveragePremiums,
        public array $loadings,
        public array $discounts,
        public int $minimumAdjustmentMinor,
        public int $roundingAdjustmentMinor,
        public int $netPremiumMinor,
        public array $duties,
        public int $dutiesTotalMinor,
        public int $grossPremiumMinor,
        public array $explanation,
        public bool $verify,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency, 'as_of' => $this->asOf, 'product_version_id' => $this->productVersionId, 'plan' => $this->plan,
            'inputs_hash' => $this->inputsHash, 'risk_inputs' => $this->riskInputs, 'coverages' => $this->coverages,
            'base_premium_minor' => $this->basePremiumMinor, 'coverage_premiums' => $this->coveragePremiums, 'loadings' => $this->loadings, 'discounts' => $this->discounts,
            'minimum_adjustment_minor' => $this->minimumAdjustmentMinor, 'rounding_adjustment_minor' => $this->roundingAdjustmentMinor,
            'net_premium_minor' => $this->netPremiumMinor, 'duties' => $this->duties, 'duties_total_minor' => $this->dutiesTotalMinor,
            'gross_premium_minor' => $this->grossPremiumMinor, 'explanation' => $this->explanation, 'verify' => $this->verify,
        ];
    }

    /** @param array<string, mixed> $data the shape of toArray() */
    public static function fromArray(array $data): self
    {
        $plan = self::arrayOf($data, 'plan');

        return new self(
            self::stringOf($data, 'currency'), self::stringOf($data, 'as_of'), is_string($data['product_version_id'] ?? null) ? $data['product_version_id'] : null,
            ['id' => is_string($plan['id'] ?? null) ? $plan['id'] : null, 'code' => self::stringOf($plan, 'code'), 'version' => self::intOf($plan, 'version'),
                'class_code' => self::stringOf($plan, 'class_code')],
            self::stringOf($data, 'inputs_hash'),
            array_map(fn (mixed $v): int|string|bool|null => is_int($v) || is_string($v) || is_bool($v) || $v === null ? $v : throw new InvalidArgumentException('Risk inputs are scalars.'),
                self::arrayOf($data, 'risk_inputs')),
            array_values(array_map('strval', self::arrayOf($data, 'coverages'))),
            self::intOf($data, 'base_premium_minor'),
            array_values(array_map(fn (mixed $c): array => ['code' => self::stringOf(self::row($c), 'code'), 'amount_minor' => self::intOf(self::row($c), 'amount_minor')],
                self::arrayOf($data, 'coverage_premiums'))),
            self::lines(self::arrayOf($data, 'loadings')), self::lines(self::arrayOf($data, 'discounts')),
            self::intOf($data, 'minimum_adjustment_minor'), self::intOf($data, 'rounding_adjustment_minor'), self::intOf($data, 'net_premium_minor'),
            self::lines(self::arrayOf($data, 'duties')), self::intOf($data, 'duties_total_minor'), self::intOf($data, 'gross_premium_minor'),
            array_values(array_map(fn (mixed $e): array => ['step_code' => self::stringOf(self::row($e), 'step_code'), 'kind' => self::stringOf(self::row($e), 'kind'),
                'label_en' => self::stringOf(self::row($e), 'label_en'), 'label_bn' => self::stringOf(self::row($e), 'label_bn'),
                'amount_minor' => self::intOf(self::row($e), 'amount_minor'), 'running_total_minor' => self::intOf(self::row($e), 'running_total_minor')],
                self::arrayOf($data, 'explanation'))),
            (bool) ($data['verify'] ?? false),
        );
    }

    /**
     * @param array<mixed> $lines
     * @return list<array{code: string, label_en: string, label_bn: string, amount_minor: int}>
     */
    private static function lines(array $lines): array
    {
        return array_values(array_map(fn (mixed $l): array => ['code' => self::stringOf(self::row($l), 'code'), 'label_en' => self::stringOf(self::row($l), 'label_en'),
            'label_bn' => self::stringOf(self::row($l), 'label_bn'), 'amount_minor' => self::intOf(self::row($l), 'amount_minor')], $lines));
    }

    /** @return array<mixed> */
    private static function row(mixed $value): array
    {
        return is_array($value) ? $value : throw new InvalidArgumentException('A rating result line must be an object.');
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function arrayOf(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Rating result {$key} is missing.");
    }

    /** @param array<mixed> $data */
    private static function stringOf(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Rating result {$key} is missing.");
    }

    /** @param array<mixed> $data */
    private static function intOf(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Rating result {$key} must be an integer.");
    }
}
