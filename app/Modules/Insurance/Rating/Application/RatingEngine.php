<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Product\Domain\Models\Coverage;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use App\Modules\Insurance\Rating\Domain\RatingCalculator;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Rating\Domain\RatingRequest;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use Carbon\CarbonImmutable;

/**
 * Phase 3 design §0: `rate(productVersion, riskInputs, asOfDate) → RatingResult`. Reads only — no writes, no audit, no permission: whoever quotes
 * checks their own permission. Same inputs and same data always give the same result.
 *
 * The plan: the version's `rating_plan_id` when set (it must be active and in force on the date), otherwise the active plan for the version's class
 * on the date. Duties: those on the books for the class on the date (the calculator applies the version's duty profile).
 */
final class RatingEngine
{
    public function __construct(
        private readonly RatingPlanRepository $plans,
        private readonly DutyBook $duties,
        private readonly RatingCalculator $calculator,
    ) {}

    /**
     * @param array<mixed> $riskInputs field key → value, validated against the version's risk schema
     * @param list<string> $coverages optional coverages chosen (mandatory ones are always rated)
     *
     * @throws RatingFailed PRODUCT_NOT_RATED, RATING_PLAN_NOT_FOUND, RATING_PLAN_NOT_ACTIVE, RATING_PLAN_NOT_EFFECTIVE and every calculation failure
     * @throws \App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid
     */
    public function rate(ProductVersion|string $productVersion, array $riskInputs, CarbonImmutable $asOf, array $coverages = []): RatingResult
    {
        $version = $productVersion instanceof ProductVersion ? $productVersion : ProductVersion::query()->whereKey($productVersion)->firstOrFail();
        if ($version->class_code === null) {
            throw new RatingFailed('PRODUCT_NOT_RATED', "Product version {$version->version} has no product class, so it has no rating plan.");
        }
        $classCode = $version->class_code;
        $plan = $this->plan($version, $classCode, $asOf);

        return $this->calculator->calculate(new RatingRequest(
            plan: $this->plans->definition($plan),
            schema: $version->riskSchema(),
            riskInputs: $riskInputs,
            asOf: $asOf->toDateString(),
            coverages: array_values(Coverage::query()->where('product_version_id', $version->id)->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (Coverage $c): array => ['code' => $c->code, 'name_en' => $c->name_en, 'name_bn' => $c->name_bn, 'mandatory' => $c->mandatory])->all()),
            chosenCoverages: $coverages,
            duties: $this->duties->inForce($classCode, $asOf),
            dutyProfile: $version->dutyProfile(),
            productMinimumMinor: $version->min_premium_minor,
            productVersionId: $version->id,
        ));
    }

    private function plan(ProductVersion $version, string $classCode, CarbonImmutable $asOf): RatingPlan
    {
        $day = $asOf->toDateString();
        if ($version->rating_plan_id === null) {
            return $this->plans->activeFor($classCode, $asOf)
                ?? throw new RatingFailed('RATING_PLAN_NOT_FOUND', "No rating plan is active for class {$classCode} on {$day}.");
        }
        $plan = RatingPlan::query()->whereKey($version->rating_plan_id)->firstOrFail();
        if ($plan->status !== RatingPlanStatus::Active) {
            throw new RatingFailed('RATING_PLAN_NOT_ACTIVE', "Rating plan {$plan->code} v{$plan->version} named by the product is {$plan->status->value}.");
        }
        if ($plan->effective_from->toDateString() > $day || ($plan->effective_to !== null && $plan->effective_to->toDateString() <= $day)) {
            throw new RatingFailed('RATING_PLAN_NOT_EFFECTIVE', "Rating plan {$plan->code} v{$plan->version} is not in force on {$day}.");
        }

        return $plan;
    }
}
