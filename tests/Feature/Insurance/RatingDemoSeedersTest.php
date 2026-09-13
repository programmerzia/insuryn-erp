<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskField;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBusinessSeeder;
use Database\Seeders\PartADemoSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\seed;
use function Pest\Laravel\travelTo;

/** Phase 3 (slice R1): the local demo and the Part A story give their demo products a class, a risk schema and coverages. */
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
        });
    }
});
