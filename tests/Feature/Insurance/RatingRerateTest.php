<?php

declare(strict_types=1);

use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\ManualLoading;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use Carbon\CarbonImmutable;

use function Pest\Laravel\travelTo;

/**
 * Slice R5 (Phase 3 design §2 step 2 counter-offer, §5 manual overrides; D-32): RatingEngine::rerate(result, loading) rates a stored result again on its own plan
 * version and date — identical without a loading, even after the tariff changed — and a manual loading is applied after the plan's premium steps and the
 * product minimum, before rounding and duties. Amounts worked by hand from the golden motor quote (tests/Fixtures/rating/01).
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = ratedProductsWorld($this->ctx);
    $this->original = asTenant($this->ctx['tenant_id'], fn () => app(RatingEngine::class)->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'), ['passenger_liability']));
});

it('rates a stored result again identically on its own plan version, even after a newer tariff took over', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/01_motor_comprehensive.json'), true, 512, JSON_THROW_ON_ERROR);
    activeRatingPlan($this->ctx['tenant_id'], [...$fixture['plan'], 'version' => 2, 'effective_from' => '2026-09-15', 'steps' => array_values(array_filter($fixture['plan']['steps'], fn (array $s): bool => $s['code'] !== 'young_driver'))], supersede: true);

    asTenant($this->ctx['tenant_id'], function (): void {
        $engine = app(RatingEngine::class);
        $again = $engine->rerate(App\Modules\Insurance\Rating\Domain\RatingResult::fromArray($this->original->toArray()));
        expect($again->toArray())->toBe($this->original->toArray())
            ->and($engine->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'), ['passenger_liability'])->plan['version'])->toBe(2);
    });
});

it('applies a manual loading before rounding and duties, labelled as special terms in English and Bangla', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $loaded = app(RatingEngine::class)->rerate($this->original, new ManualLoading(1000, 'Ride sharing'));

        // Premium before rounding 26,842.43; loading 10% = 2,684.243 → 2,684.24 (half-even); 29,526.67 rounds to 29,527.00; stamp 50.00; VAT 15% of 29,527.00 = 4,429.05.
        expect($loaded->netPremiumMinor)->toBe(2_952_700)->and($loaded->roundingAdjustmentMinor)->toBe(33)
            ->and($loaded->dutiesTotalMinor)->toBe(5_000 + 442_905)->and($loaded->grossPremiumMinor)->toBe(3_400_605)
            ->and($loaded->loadings)->toBe([
                ['code' => 'young_driver', 'label_en' => 'Young driver loading 10%', 'label_bn' => 'তরুণ চালক লোডিং ১০%', 'amount_minor' => 305_028],
                ['code' => 'manual_loading', 'label_en' => 'Special terms loading 10.00%', 'label_bn' => 'বিশেষ শর্ত লোডিং ১০.০০%', 'amount_minor' => 268_424],
            ])
            ->and(array_column($loaded->explanation, 'step_code'))->toBe(['own_damage', 'third_party', 'passenger_liability', 'young_driver', 'no_claim_bonus', 'minimum', 'manual_loading', 'rounding', 'stamp', 'vat'])
            ->and($loaded->inputsHash)->not->toBe($this->original->inputsHash)
            ->and($loaded->plan)->toBe($this->original->plan);
    });
});

it('refuses a loading without a reason or outside 0.01% to 100%, and a result without a plan', function (): void {
    expect(thrownBy(fn () => new ManualLoading(1000, ' '), RatingFailed::class)->reasonCode)->toBe('LOADING_REASON_REQUIRED')
        ->and(thrownBy(fn () => new ManualLoading(0, 'x'), RatingFailed::class)->reasonCode)->toBe('MANUAL_LOADING_INVALID')
        ->and(thrownBy(fn () => new ManualLoading(10_001, 'x'), RatingFailed::class)->reasonCode)->toBe('MANUAL_LOADING_INVALID')
        ->and((new ManualLoading(1250, 'x'))->labelEn())->toBe('Special terms loading 12.50%');
    asTenant($this->ctx['tenant_id'], function (): void {
        $orphan = App\Modules\Insurance\Rating\Domain\RatingResult::fromArray([...$this->original->toArray(), 'plan' => [...$this->original->plan, 'id' => null]]);
        expect(thrownBy(fn () => app(RatingEngine::class)->rerate($orphan), RatingFailed::class)->reasonCode)->toBe('RATING_PLAN_NOT_FOUND');
    });
});
