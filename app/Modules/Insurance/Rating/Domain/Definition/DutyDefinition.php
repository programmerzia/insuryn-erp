<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Rating\Domain\Enums\DutyBasis;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Rating\Domain\RatingMath;

/**
 * A duty on premium (Phase 3 design §1 duties): VAT, stamp duty or a levy, for the listed product classes, in force on [effective_from, effective_to).
 * `verify` marks a placeholder value still to be confirmed against current NBR/IDRA rules (design OPEN 1).
 */
final readonly class DutyDefinition
{
    /**
     * @param list<string> $classCodes
     * @param list<array{from: int, to: int|null, amount_minor: int}> $bands
     */
    public function __construct(
        public string $code,
        public DutyBasis $basis,
        public ?int $rateBp,
        public ?int $amountMinor,
        public array $bands,
        public array $classCodes,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $labelEn,
        public string $labelBn,
        public bool $verify = true,
    ) {}

    public function appliesTo(string $classCode, string $day): bool
    {
        return in_array($classCode, $this->classCodes, true) && $this->effectiveFrom <= $day && ($this->effectiveTo === null || $day < $this->effectiveTo);
    }

    /** The duty on a net premium, in minor units (VAT and levies on the net premium only, never on other duties: A-68). */
    public function amountFor(int $netPremiumMinor, int $sumInsuredMinor): int
    {
        return match ($this->basis) {
            DutyBasis::PctOfPremium => RatingMath::pct($netPremiumMinor, (int) $this->rateBp),
            DutyBasis::FlatPerPolicy => (int) $this->amountMinor,
            DutyBasis::PerSumInsuredBand => $this->bandAmount($sumInsuredMinor),
        };
    }

    private function bandAmount(int $sumInsuredMinor): int
    {
        foreach ($this->bands as $band) {
            if ($band['from'] <= $sumInsuredMinor && ($band['to'] === null || $sumInsuredMinor < $band['to'])) {
                return $band['amount_minor'];
            }
        }

        throw new RatingFailed('DUTY_NOT_FOUND', "Duty {$this->code} has no band for a sum insured of {$sumInsuredMinor}.");
    }

    /** @param array<mixed> $duty */
    public static function fromArray(array $duty): self
    {
        $code = $duty['code'] ?? null;
        $basis = is_string($duty['basis'] ?? null) ? DutyBasis::tryFrom($duty['basis']) : null;
        $classes = $duty['class_codes'] ?? null;
        $rate = $duty['rate_bp'] ?? null;
        $amount = $duty['amount_minor'] ?? null;
        $from = $duty['effective_from'] ?? null;
        $to = $duty['effective_to'] ?? null;
        if (! in_array($code, DutyProfile::DUTY_CODES, true) || $basis === null || ! is_array($classes) || $classes === [] || ! array_is_list($classes)
            || array_filter($classes, fn (mixed $c): bool => ! is_string($c)) !== [] || ! is_string($from) || ($to !== null && (! is_string($to) || $to <= $from))
            || ! is_string($duty['label_en'] ?? null) || ! is_string($duty['label_bn'] ?? null) || ! is_bool($duty['verify'] ?? true)
            || ($rate !== null && (! is_int($rate) || $rate < 0 || $rate > 10_000)) || ($amount !== null && (! is_int($amount) || $amount < 0))) {
            throw PlanDefinitionInvalid::because('A duty needs a code (vat, stamp, levy), a basis, a non-empty list of class codes, effective_from before effective_to, label_en, label_bn, '
                .'and integer rate_bp (0–10000) or amount_minor.');
        }
        $bands = self::bands($duty['bands'] ?? null);
        $valid = match ($basis) {
            DutyBasis::PctOfPremium => $rate !== null && $amount === null && $bands === [],
            DutyBasis::FlatPerPolicy => $amount !== null && $rate === null && $bands === [],
            DutyBasis::PerSumInsuredBand => $bands !== [] && $rate === null && $amount === null,
        };
        if (! $valid) {
            throw PlanDefinitionInvalid::because("Duty {$code}: pct_of_premium takes rate_bp only, flat_per_policy amount_minor only, per_sum_insured_band bands only.");
        }

        return new self($code, $basis, $rate, $amount, $bands, array_map('strval', $classes), $from, $to, $duty['label_en'], $duty['label_bn'], $duty['verify'] ?? true);
    }

    /** @return list<array{from: int, to: int|null, amount_minor: int}> */
    private static function bands(mixed $bands): array
    {
        if ($bands === null) {
            return [];
        }
        if (! is_array($bands) || ! array_is_list($bands)) {
            throw PlanDefinitionInvalid::because('Duty bands must be a list of {from, to, amount_minor}.');
        }
        $parsed = [];
        foreach ($bands as $band) {
            if (! is_array($band) || ! is_int($band['from'] ?? null) || ! (is_int($band['to'] ?? null) || ($band['to'] ?? null) === null)
                || ! is_int($band['amount_minor'] ?? null) || $band['amount_minor'] < 0 || (is_int($band['to'] ?? null) && $band['to'] <= $band['from'])) {
                throw PlanDefinitionInvalid::because('Each duty band needs integer from < to (to may be null) and a non-negative amount_minor.');
            }
            $parsed[] = ['from' => $band['from'], 'to' => $band['to'] ?? null, 'amount_minor' => $band['amount_minor']];
        }
        usort($parsed, fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        foreach (array_slice($parsed, 1) as $i => $band) {
            $previous = $parsed[$i];
            if ($previous['to'] === null || $band['from'] < $previous['to']) {
                throw PlanDefinitionInvalid::because('Duty bands overlap.');
            }
        }

        return $parsed;
    }

    /** @return array{code: string, basis: string, rate_bp: int|null, amount_minor: int|null, bands: list<array{from: int, to: int|null, amount_minor: int}>, class_codes: list<string>, effective_from: string, effective_to: string|null, label_en: string, label_bn: string, verify: bool} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'basis' => $this->basis->value, 'rate_bp' => $this->rateBp, 'amount_minor' => $this->amountMinor, 'bands' => $this->bands,
            'class_codes' => $this->classCodes, 'effective_from' => $this->effectiveFrom, 'effective_to' => $this->effectiveTo, 'label_en' => $this->labelEn,
            'label_bn' => $this->labelBn, 'verify' => $this->verify];
    }
}
