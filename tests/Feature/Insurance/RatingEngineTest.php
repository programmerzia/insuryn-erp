<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §0 (slice R3): RatingEngine::rate(productVersion, riskInputs, asOf) — which plan it uses, the product's minimum premium and duty
 * profile, clear failures, and no side effects.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->admin = userWithPermissions($this->ctx['tenant_id'], ['product.manage', 'rating.manage_plans']);
    $this->fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/02_fire_per_mille_by_occupancy.json'), true, 512, JSON_THROW_ON_ERROR);
    $this->planId = activeRatingPlan($this->ctx['tenant_id'], $this->fixture['plan']);
    $this->engine = app(RatingEngine::class);
    $this->inputs = $this->fixture['request']['risk_inputs'];
    $this->day = CarbonImmutable::parse('2026-09-15');
    asTenant($this->ctx['tenant_id'], function (): void {
        foreach ($this->fixture['duties'] as $duty) {
            app(DutyBook::class)->record($duty, $this->admin);
        }
        $this->catalogue = app(ProductCatalogue::class);
        $this->product = $this->catalogue->createProduct('FIRE', 'Fire', 'fire', $this->admin);
    });
    /** A fire product version with the fixture's schema and coverages; returns its id. */
    $this->version = fn (array $terms = []): string => $this->catalogue->addVersion($this->product->id, ['effective_from' => '2026-01-01', 'term_months' => 12,
        'earning_method' => 'monthly', 'tax_profile' => ['inclusive' => false], 'class_code' => 'fire', 'risk_schema' => $this->fixture['product']['risk_schema'],
        'coverage_definitions' => $this->fixture['product']['coverages'], ...$terms], $this->admin)->id;
});

it('rates with the active plan for the class and writes nothing', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $version = ($this->version)();
        $tables = ['audit_events', 'rating_plans', 'rate_tables', 'rate_table_rows', 'rating_steps', 'duties', 'product_versions', 'coverages', 'outbox', 'policies'];
        $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        $before = $counts();

        $first = $this->engine->rate($version, $this->inputs, $this->day);
        $second = $this->engine->rate($version, $this->inputs, $this->day);

        expect($first->grossPremiumMinor)->toBe(5_700_640)
            ->and($first->plan)->toBe(['id' => $this->planId, 'code' => 'FIRE-TARIFF', 'version' => 1, 'class_code' => 'fire'])
            ->and($second->toArray())->toBe($first->toArray())
            ->and($counts())->toBe($before);
    });
});

it('applies the product minimum premium when it is above the plan minimum, and the duty profile', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $small = [...$this->inputs, 'sum_insured' => 1_000_000]; // 10,000.00 × 2.00 ‰ = 2,000 minor units, below both minimums
        $plain = $this->engine->rate(($this->version)(['effective_to' => '2026-02-01']), $small, $this->day->setDate(2026, 1, 15));
        $withMinimum = $this->engine->rate(($this->version)(['effective_from' => '2026-02-01', 'effective_to' => '2026-03-01', 'min_premium_minor' => 250_000, 'duty_profile' => ['exclude' => ['stamp']]]),
            $small, $this->day->setDate(2026, 2, 15));

        expect($plain->netPremiumMinor)->toBe(100_000) // plan minimum step
            ->and($plain->minimumAdjustmentMinor)->toBe(98_000)
            ->and(array_column($plain->duties, 'code'))->toBe(['stamp', 'vat'])
            ->and($withMinimum->netPremiumMinor)->toBe(250_000) // the product's higher minimum (A-67)
            ->and($withMinimum->minimumAdjustmentMinor)->toBe(248_000)
            ->and(array_column($withMinimum->explanation, 'step_code'))->toBe(['fire_basic', 'minimum', 'product_minimum', 'rounding', 'vat'])
            ->and($withMinimum->duties)->toBe([['code' => 'vat', 'label_en' => 'VAT', 'label_bn' => 'মূসক', 'amount_minor' => 37_500]])
            ->and($withMinimum->grossPremiumMinor)->toBe(287_500);
    });
});

it('uses the plan the product version names, and refuses one that is not active or not in force', function (): void {
    $maker = userWithPermissions($this->ctx['tenant_id'], ['rating.manage_plans']);
    $checker = userWithPermissions($this->ctx['tenant_id'], ['rating.approve_plans']);
    asTenant($this->ctx['tenant_id'], function () use ($maker, $checker): void {
        $plans = app(RatingPlanService::class);
        $pinned = ($this->version)(['effective_to' => '2026-06-01', 'rating_plan_id' => $this->planId]);
        $draft = $plans->newVersion($this->planId, $maker, '2027-01-01');
        $onDraft = ($this->version)(['effective_from' => '2026-06-01', 'effective_to' => '2026-07-01', 'rating_plan_id' => $draft->id]);
        $noClass = $this->catalogue->addVersion($this->catalogue->createProduct('OLD', 'Phase 1', 'fire', $this->admin)->id,
            ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'tax_profile' => []], $this->admin)->id;

        expect($this->engine->rate($pinned, $this->inputs, $this->day->setDate(2026, 3, 1))->plan['id'])->toBe($this->planId)
            ->and(thrownBy(fn () => $this->engine->rate($onDraft, $this->inputs, $this->day->setDate(2026, 6, 15)), RatingFailed::class)->reasonCode)->toBe('RATING_PLAN_NOT_ACTIVE')
            ->and(thrownBy(fn () => $this->engine->rate($noClass, $this->inputs, $this->day), RatingFailed::class)->reasonCode)->toBe('PRODUCT_NOT_RATED')
            ->and(thrownBy(fn () => ($this->version)(['effective_from' => '2026-07-01', 'rating_plan_id' => (string) Illuminate\Support\Str::uuid7()]), App\Modules\Platform\Exceptions\BusinessRuleViolation::class)->reasonCode)
            ->toBe('RATING_PLAN_UNKNOWN')
            ->and(thrownBy(fn () => $this->catalogue->configureRating($noClass, ['rating_plan_id' => $this->planId], $this->admin), App\Modules\Platform\Exceptions\BusinessRuleViolation::class)->reasonCode)
            ->toBe('RATING_PLAN_CLASS_MISMATCH');

        $plans->approve($draft->id, $checker);
        $plans->activate($draft->id, $checker, supersede: true);
        expect(thrownBy(fn () => $this->engine->rate($onDraft, $this->inputs, $this->day->setDate(2026, 6, 15)), RatingFailed::class)->reasonCode)->toBe('RATING_PLAN_NOT_EFFECTIVE');
    });
});

it('fails clearly on bad inputs, unknown coverages and missing rates', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $version = ($this->version)();

        expect(thrownBy(fn () => $this->engine->rate($version, [...$this->inputs, 'occupancy' => 'stadium'], $this->day), RiskInputsInvalid::class)->errors)->toBe(['occupancy' => 'NOT_AN_OPTION'])
            ->and(thrownBy(fn () => $this->engine->rate($version, $this->inputs, $this->day, ['earthquake']), RatingFailed::class)->reasonCode)->toBe('COVERAGE_UNKNOWN')
            ->and(thrownBy(fn () => $this->engine->rate($version, $this->inputs, CarbonImmutable::parse('2025-06-01')), RatingFailed::class)->reasonCode)->toBe('RATING_PLAN_NOT_FOUND');
    });
});
