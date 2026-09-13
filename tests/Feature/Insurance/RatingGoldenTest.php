<?php

declare(strict_types=1);

use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;
use App\Modules\Insurance\Rating\Domain\RatingCalculator;
use App\Modules\Insurance\Rating\Domain\RatingRequest;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use Carbon\CarbonImmutable;

/**
 * Phase 3 design §1 (slice R3) golden fixtures, like the posting-rule and compensation fixtures: each file in tests/Fixtures/rating states a product
 * (class, risk schema, coverages), a rating plan, duties, a quote and the exact expected result, worked by hand. The tariffs are illustrative
 * placeholders flagged "verify". Each fixture is rated twice: by the pure calculator from the file, and by RatingEngine through the database after
 * the plan is drafted, approved and activated. Change the engine → update the fixture, never the other way.
 */
$fixtures = glob(__DIR__.'/../../Fixtures/rating/*.json') ?: [];

/**
 * The result without the ids a database run assigns (plan id, product version id) and the inputs hash, which the test checks separately.
 *
 * @return array<string, mixed>
 */
function comparableRating(RatingResult $result): array
{
    $data = $result->toArray();
    unset($data['product_version_id'], $data['inputs_hash'], $data['plan']['id']);

    return $data;
}

it('rates golden fixture :dataset', function (string $file): void {
    /** @var array{product: array{class_code: string, risk_schema: list<array<string, mixed>>, min_premium_minor: int|null, duty_profile: array<string, mixed>|null, coverages: list<array{code: string, name_en: string, name_bn: string, basis: string, mandatory: bool}>}, plan: array<string, mixed>, duties: list<array<string, mixed>>, request: array{as_of: string, risk_inputs: array<string, mixed>, coverages: list<string>}, expected: array<string, mixed>} $fx */
    $fx = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    $pure = (new RatingCalculator())->calculate(new RatingRequest(
        RatingPlanDefinition::fromArray($fx['plan']), RiskSchema::fromArray($fx['product']['risk_schema']), $fx['request']['risk_inputs'], $fx['request']['as_of'],
        array_map(fn (array $c): array => ['code' => $c['code'], 'name_en' => $c['name_en'], 'name_bn' => $c['name_bn'], 'mandatory' => $c['mandatory']], $fx['product']['coverages']),
        $fx['request']['coverages'], array_map(fn (array $d): DutyDefinition => DutyDefinition::fromArray($d), $fx['duties']),
        DutyProfile::fromArray($fx['product']['duty_profile']), $fx['product']['min_premium_minor'],
    ));
    expect(comparableRating($pure))->toBe($fx['expected'])
        ->and($pure->inputsHash)->toMatch('/^[0-9a-f]{64}$/')
        ->and(RatingResult::fromArray($pure->toArray()))->toEqual($pure);

    $ctx = seedDemoTenant();
    $admin = userWithPermissions($ctx['tenant_id'], ['product.manage', 'rating.manage_plans']);
    $planId = activeRatingPlan($ctx['tenant_id'], $fx['plan']);
    [$versionId, $result] = asTenant($ctx['tenant_id'], function () use ($fx, $admin): array {
        foreach ($fx['duties'] as $duty) {
            app(DutyBook::class)->record($duty, $admin);
        }
        $catalogue = app(ProductCatalogue::class);
        $product = $catalogue->createProduct(strtoupper($fx['product']['class_code']), 'Golden '.$fx['product']['class_code'], $fx['product']['class_code'], $admin);
        $version = $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'tax_profile' => ['inclusive' => false],
            'class_code' => $fx['product']['class_code'], 'risk_schema' => $fx['product']['risk_schema'], 'duty_profile' => $fx['product']['duty_profile'],
            'min_premium_minor' => $fx['product']['min_premium_minor'], 'coverage_definitions' => $fx['product']['coverages']], $admin);

        return [$version->id, app(RatingEngine::class)->rate($version->id, $fx['request']['risk_inputs'], CarbonImmutable::parse($fx['request']['as_of']), $fx['request']['coverages'])];
    });

    expect(comparableRating($result))->toBe($fx['expected'])
        ->and($result->plan['id'])->toBe($planId)
        ->and($result->productVersionId)->toBe($versionId)
        ->and($result->inputsHash)->toBe($pure->inputsHash);
})->with(array_combine(array_map('basename', $fixtures), $fixtures));
