<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Domain\Enums\RiskStage;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskField;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBusinessSeeder;
use Database\Seeders\PartADemoSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\seed;
use function Pest\Laravel\travelTo;

/** Phase 3: the local demo and the Part A story give their demo products a class, a risk schema and coverages (R1) and active placeholder tariffs (R3). */
it('gives the demo products of the local demo and the Part A story their class, risk schema and coverages', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    app()->detectEnvironment(fn (): string => 'local');
    seed(DatabaseSeeder::class);
    seed(DemoBusinessSeeder::class);
    (new PartADemoSeeder())->run();

    foreach (['demo', 'nonlife'] as $slug) {
        $tenantId = (string) DB::table('tenants')->where('slug', $slug)->value('id');
        asTenant($tenantId, function () use ($slug): void {
            $classes = DB::table('product_versions as v')->join('products as p', 'p.id', '=', 'v.product_id')->orderBy('p.code')->pluck('v.class_code', 'p.code')->all();
            expect($classes)->toBe(['FIRE' => 'fire', 'MARINE' => 'marine_cargo', 'MOTOR' => 'motor'], $slug);
            foreach (ProductVersion::query()->get() as $version) {
                expect($version->riskSchema()->field('sum_insured')?->required)->toBeTrue()
                    ->and($version->coverageDefinitions()->where('mandatory', true)->count())->toBeGreaterThan(0);
            }
            $motor = ProductVersion::query()->where('class_code', 'motor')->firstOrFail()->riskSchema();
            expect(array_map(fn (RiskField $f): string => $f->key, $motor->fields))
                ->toBe(['vehicle_type', 'registration_no', 'chassis_no', 'engine_cc', 'seats', 'year_of_manufacture', 'driver_age', 'sum_insured', 'ncb_years']);
            // Flow fix X7: the chassis number is needed for the proposal, not the quote; a quote starts as a private car.
            expect($motor->field('chassis_no')?->requiredAt)->toBe(RiskStage::Proposal)
                ->and($motor->field('registration_no')?->requiredAt)->toBe(RiskStage::Quote)
                ->and($motor->field('vehicle_type')?->default)->toBe('private');

            // Slice R3: an active placeholder plan per MVP class, duties flagged verify, and the demo products rate.
            expect(DB::table('rating_plans')->where('status', 'active')->where('verify', true)->orderBy('class_code')->pluck('class_code')->all())
                ->toBe(['fire', 'marine_cargo', 'misc', 'motor'])
                ->and(DB::table('duties')->where('verify', false)->count())->toBe(0)
                ->and(DB::table('duties')->distinct()->pluck('source')->all())->toBe(['placeholder_verify']);
            $result = app(RatingEngine::class)->rate(ProductVersion::query()->where('class_code', 'motor')->firstOrFail(), ['vehicle_type' => 'private',
                'registration_no' => 'DHA-1', 'chassis_no' => 'CH-1', 'engine_cc' => 1500, 'seats' => 5, 'year_of_manufacture' => 2020, 'driver_age' => 23, 'sum_insured' => 123_456_700,
                'ncb_years' => 2], CarbonImmutable::parse('2026-09-15'), ['passenger_liability']);
            expect($result->grossPremiumMinor)->toBe(3_091_830)->and($result->verify)->toBeTrue(); // the motor golden fixture's quote
        });
    }
});
