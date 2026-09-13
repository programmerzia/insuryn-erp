<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingStepDefinition;
use App\Modules\Insurance\Rating\Domain\Enums\RatingStepKind;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingContext;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingExpressions;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingScope;

/**
 * Phase 3 design §0 DECISION "rating is a pure, deterministic, versioned function": `calculate(request) → RatingResult`, integers only, no I/O.
 *
 * 1. The risk inputs are validated against the product version's risk schema.
 * 2. Steps run in order_no over `risk.*`, `coverage.*`, `sum_insured` (risk input sum_insured, else 0), `running.premium` and `steps.<code>`;
 *    a step with `applies_to` runs only when that coverage is rated, a step with a false condition is skipped.
 *    base/coverage/loading add their amount, discount takes it off, minimum raises the premium to its amount, rounding replaces the premium.
 * 3. The product version's minimum premium applies after the plan's premium steps, before rounding: the higher of the plan's and the product's
 *    minimum wins (ASSUMPTION A-67).
 * 4. Duties: duty and tax steps (`duty('vat')`), then every other duty in force for the plan's class on the day that the product's duty profile does
 *    not exclude, in the order stamp, levy, vat — on the net premium only (A-68). Gross = net + duties.
 */
final class RatingCalculator
{
    public function __construct(private readonly RatingExpressions $expressions = new RatingExpressions()) {}

    public function calculate(RatingRequest $request): RatingResult
    {
        $plan = $request->plan;
        $day = $request->asOf;
        $inputs = $request->schema->validate($request->riskInputs);
        $sumInsured = is_int($inputs['sum_insured'] ?? null) ? $inputs['sum_insured'] : 0;
        $rated = $this->ratedCoverages($request);
        $profile = $request->dutyProfile ?? DutyProfile::fromArray(null);
        $duties = array_values(array_filter($request->duties, fn (DutyDefinition $d): bool => $d->appliesTo($plan->classCode, $day) && $profile->applies($d->code)));

        $running = 0;
        $stepAmounts = [];
        $base = 0;
        $coveragePremiums = [];
        $loadings = [];
        $discounts = [];
        $minimumAdjustment = 0;
        $roundingAdjustment = 0;
        $dutyLines = [];
        $dutiesTotal = 0;
        $dutiesUsed = [];
        $explanation = [];
        $productMinimumDone = false;

        $explain = function (string $code, string $kind, string $labelEn, string $labelBn, int $amount, int $total) use (&$explanation): void {
            $explanation[] = ['step_code' => $code, 'kind' => $kind, 'label_en' => $labelEn, 'label_bn' => $labelBn, 'amount_minor' => $amount, 'running_total_minor' => $total];
        };
        $productMinimum = function () use ($request, &$running, &$minimumAdjustment, &$productMinimumDone, &$loadings, $explain): void {
            $productMinimumDone = true;
            if ($request->productMinimumMinor !== null && $running < $request->productMinimumMinor) {
                $uplift = $request->productMinimumMinor - $running;
                $running = $request->productMinimumMinor;
                $minimumAdjustment += $uplift;
                $explain('product_minimum', RatingStepKind::Minimum->value, 'Minimum premium of the product', 'পণ্যের ন্যূনতম প্রিমিয়াম', $uplift, $running);
            }
            // Slice R5 (D-32): an underwriter's manual loading, after the premium steps and minimums, before rounding and duties.
            $loading = $request->manualLoading;
            if ($loading !== null) {
                $amount = RatingMath::pct($running, $loading->basisPoints);
                $running = RatingMath::add($running, $amount);
                $loadings[] = ['code' => 'manual_loading', 'label_en' => $loading->labelEn(), 'label_bn' => $loading->labelBn(), 'amount_minor' => $amount];
                $explain('manual_loading', RatingStepKind::Loading->value, $loading->labelEn(), $loading->labelBn(), $amount, $running);
            }
        };

        foreach ($plan->steps as $step) {
            if ($step->kind->phase() > 1 && ! $productMinimumDone) {
                $productMinimum();
            }
            if ($step->appliesTo !== null && ! isset($rated[$step->appliesTo])) {
                continue;
            }
            $netSoFar = $running;
            $context = new RatingContext($plan->tablesByCode(), $day, function (string $code) use ($request, $duties, $profile, $netSoFar, $sumInsured, &$dutiesUsed): int {
                $dutiesUsed[$code] = true;
                if (! $profile->applies($code)) {
                    return 0;
                }
                foreach ($duties as $duty) {
                    if ($duty->code === $code) {
                        return $duty->amountFor($netSoFar, $sumInsured);
                    }
                }

                throw new RatingFailed('DUTY_NOT_FOUND', "No {$code} duty is in force for class {$request->plan->classCode} on {$request->asOf}.");
            });
            $variables = [
                'risk' => new RatingScope('risk', $inputs),
                'coverage' => new RatingScope('coverage', $step->appliesTo === null ? [] : $rated[$step->appliesTo]),
                'sum_insured' => $sumInsured,
                'running' => new RatingScope('running', ['premium' => $running]),
                'steps' => new RatingScope('steps', $stepAmounts),
            ];
            if ($step->condition !== null && ! $this->expressions->condition($step->condition, $variables, $context)) {
                continue;
            }
            $amount = $this->expressions->amount($step->expression, $variables, $context);
            $stepAmounts[$step->code] = $amount;

            switch ($step->kind) {
                case RatingStepKind::Base:
                case RatingStepKind::Coverage:
                    $this->assertNotNegative($step, $amount);
                    $running = RatingMath::add($running, $amount);
                    if ($step->kind === RatingStepKind::Base) {
                        $base += $amount;
                    }
                    if ($step->appliesTo !== null) {
                        $coveragePremiums[$step->appliesTo] = ($coveragePremiums[$step->appliesTo] ?? 0) + $amount;
                    }
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, $amount, $running);
                    break;
                case RatingStepKind::Loading:
                    $this->assertNotNegative($step, $amount);
                    $running = RatingMath::add($running, $amount);
                    $loadings[] = $this->line($step, $amount);
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, $amount, $running);
                    break;
                case RatingStepKind::Discount:
                    $this->assertNotNegative($step, $amount);
                    $running -= $amount;
                    $discounts[] = $this->line($step, $amount);
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, -$amount, $running);
                    break;
                case RatingStepKind::Minimum:
                    $uplift = max(0, $amount - $running);
                    $running += $uplift;
                    $minimumAdjustment += $uplift;
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, $uplift, $running);
                    break;
                case RatingStepKind::Rounding:
                    $roundingAdjustment += $amount - $running;
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, $amount - $running, $amount);
                    $running = $amount;
                    break;
                case RatingStepKind::Duty:
                case RatingStepKind::Tax:
                    $this->assertNotNegative($step, $amount);
                    $dutiesTotal = RatingMath::add($dutiesTotal, $amount);
                    $dutyLines[] = $this->line($step, $amount);
                    $explain($step->code, $step->kind->value, $step->labelEn, $step->labelBn, $amount, $running + $dutiesTotal);
                    break;
            }
            if ($running < 0) {
                throw new RatingFailed('RATING_NEGATIVE_PREMIUM', "The premium goes below zero at step {$step->code}.");
            }
        }
        if (! $productMinimumDone) {
            $productMinimum();
        }

        $net = $running;
        foreach ($duties as $duty) {
            if (isset($dutiesUsed[$duty->code])) {
                continue;
            }
            $amount = $duty->amountFor($net, $sumInsured);
            $dutiesTotal = RatingMath::add($dutiesTotal, $amount);
            $dutyLines[] = ['code' => $duty->code, 'label_en' => $duty->labelEn, 'label_bn' => $duty->labelBn, 'amount_minor' => $amount];
            $explain($duty->code, $duty->code === 'vat' ? RatingStepKind::Tax->value : RatingStepKind::Duty->value, $duty->labelEn, $duty->labelBn, $amount, $net + $dutiesTotal);
        }

        $coverageCodes = array_keys($rated);
        $riskInputs = $inputs;

        return new RatingResult(
            currency: $plan->currency,
            asOf: $day,
            productVersionId: $request->productVersionId,
            plan: ['id' => $plan->id, 'code' => $plan->code, 'version' => $plan->version, 'class_code' => $plan->classCode],
            inputsHash: hash('sha256', (string) json_encode(['plan' => [$plan->code, $plan->version, $plan->classCode], 'as_of' => $day, 'risk_inputs' => $riskInputs,
                'coverages' => $coverageCodes, ...($request->manualLoading === null ? [] : ['manual_loading_bp' => $request->manualLoading->basisPoints])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            riskInputs: $riskInputs,
            coverages: $coverageCodes,
            basePremiumMinor: $base,
            coveragePremiums: array_map(fn (string $code, int $amount): array => ['code' => $code, 'amount_minor' => $amount], array_keys($coveragePremiums), $coveragePremiums),
            loadings: $loadings,
            discounts: $discounts,
            minimumAdjustmentMinor: $minimumAdjustment,
            roundingAdjustmentMinor: $roundingAdjustment,
            netPremiumMinor: $net,
            duties: $dutyLines,
            dutiesTotalMinor: $dutiesTotal,
            grossPremiumMinor: RatingMath::add($net, $dutiesTotal),
            explanation: $explanation,
            verify: array_filter($duties, fn (DutyDefinition $d): bool => $d->verify) !== [],
        );
    }

    /** @return array<string, array{code: string, name_en: string, name_bn: string, mandatory: bool}> coverages rated, by code, in the version's order */
    private function ratedCoverages(RatingRequest $request): array
    {
        $known = array_column($request->coverages, null, 'code');
        $unknown = array_diff($request->chosenCoverages, array_keys($known));
        if ($unknown !== []) {
            throw new RatingFailed('COVERAGE_UNKNOWN', 'The product has no coverage '.implode(', ', $unknown).'.');
        }
        $rated = [];
        foreach ($request->coverages as $coverage) {
            if ($coverage['mandatory'] || in_array($coverage['code'], $request->chosenCoverages, true)) {
                $rated[$coverage['code']] = $coverage;
            }
        }

        return $rated;
    }

    /** @return array{code: string, label_en: string, label_bn: string, amount_minor: int} */
    private function line(RatingStepDefinition $step, int $amount): array
    {
        return ['code' => $step->code, 'label_en' => $step->labelEn, 'label_bn' => $step->labelBn, 'amount_minor' => $amount];
    }

    private function assertNotNegative(RatingStepDefinition $step, int $amount): void
    {
        if ($amount < 0) {
            throw new RatingFailed('RATING_NEGATIVE_AMOUNT', "Step {$step->code} ({$step->kind->value}) gave a negative amount; use a discount step to take premium off.");
        }
    }
}
