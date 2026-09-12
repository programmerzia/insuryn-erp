<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * Design §2.4 products / product_versions; spec §4 "Effective-dated versions; pricing, term, coverages, tax
 * rules, commission schedule, posting rule set, earning method". D-06: tax_profile.refund_tax_on_cancellation.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->manager = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['product.manage'])));
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
});

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function versionPayload(string $from, ?string $to = null, array $overrides = []): array
{
    return array_replace([
        'effective_from' => $from, 'effective_to' => $to, 'term_months' => 12, 'earning_method' => 'daily_365',
        'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true],
        'posting_rule_set' => 'default', 'coverages' => [['code' => 'OD', 'name' => 'Own damage']],
    ], $overrides);
}

it('creates a product with effective-dated versions and resolves the version in force on a date', function (): void {
    $productId = (string) actingAs($this->manager)->postJson('/api/insurance/products', ['code' => 'MOTOR-COMP', 'name' => 'Motor Comprehensive', 'lob' => 'motor'], $this->headers)
        ->assertCreated()->json('data.id');

    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-01-01', '2026-07-01'), $this->headers)
        ->assertCreated()->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.tax_profile.refund_tax_on_cancellation', true); // D-06 default (ASSUMPTION A-1)
    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-07-01', null, ['earning_method' => 'monthly', 'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => false]]), $this->headers)
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.tax_profile.refund_tax_on_cancellation', false);

    asTenant($this->ctx['tenant_id'], function () use ($productId): void {
        $catalogue = app(ProductCatalogue::class);
        expect($catalogue->versionOn($productId, CarbonImmutable::parse('2026-06-30'))->version)->toBe(1)
            ->and($catalogue->versionOn($productId, CarbonImmutable::parse('2026-07-01'))->version)->toBe(2)
            ->and($catalogue->versionOn($productId, CarbonImmutable::parse('2030-01-01'))->earning_method->value)->toBe('monthly');

        expect(thrownBy(fn () => $catalogue->versionOn($productId, CarbonImmutable::parse('2025-12-31')), BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCT_VERSION_NOT_EFFECTIVE');
    });

    actingAs($this->manager)->getJson("/api/insurance/products/{$productId}/versions/resolve?date=2026-03-15", $this->headers)
        ->assertOk()->assertJsonPath('data.version', 1);
    actingAs($this->manager)->getJson("/api/insurance/products/{$productId}", $this->headers)
        ->assertOk()->assertJsonCount(2, 'data.versions')->assertJsonPath('data.code', 'MOTOR-COMP');
});

it('refuses overlapping versions until the open version is end-dated', function (): void {
    $productId = (string) actingAs($this->manager)->postJson('/api/insurance/products', ['code' => 'FIRE', 'name' => 'Fire', 'lob' => 'fire'], $this->headers)->json('data.id');
    $first = (string) actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-01-01'), $this->headers)->json('data.id');

    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-09-01'), $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'PRODUCT_VERSION_OVERLAP');

    actingAs($this->manager)->patchJson("/api/insurance/products/{$productId}/versions/{$first}", ['effective_to' => '2026-09-01'], $this->headers)
        ->assertOk()->assertJsonPath('data.effective_to', '2026-09-01');
    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-08-15', '2026-12-31'), $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'PRODUCT_VERSION_OVERLAP');
    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-09-01'), $this->headers)
        ->assertCreated()->assertJsonPath('data.version', 2);
});

it('validates version terms and supports only the earning methods the earning batch implements', function (): void {
    $productId = (string) actingAs($this->manager)->postJson('/api/insurance/products', ['code' => 'HEALTH', 'name' => 'Health', 'lob' => 'health'], $this->headers)->json('data.id');

    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-01-01', '2025-01-01', ['term_months' => 0, 'earning_method' => '24ths', 'short_rate_table' => [['months' => 1, 'retained_pct_bp' => 2000]]]), $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['effective_to', 'term_months', 'earning_method', 'short_rate_table']);
    actingAs($this->manager)->postJson("/api/insurance/products/{$productId}/versions", versionPayload('2026-01-01', null, ['tax_profile' => ['tax_type' => 'VAT']]), $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['tax_profile.jurisdiction', 'tax_profile.inclusive']);
    actingAs($this->manager)->postJson('/api/insurance/products', ['code' => 'HEALTH', 'name' => 'Duplicate', 'lob' => 'health'], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('requires product.manage and keeps catalogues per tenant', function (): void {
    $clerk = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create'])));
    actingAs($clerk)->postJson('/api/insurance/products', ['code' => 'X', 'name' => 'X', 'lob' => 'motor'], $this->headers)->assertForbidden();

    $productId = (string) actingAs($this->manager)->postJson('/api/insurance/products', ['code' => 'MARINE', 'name' => 'Marine', 'lob' => 'marine'], $this->headers)->json('data.id');
    $other = seedDemoTenant('catalogue-other');
    $otherManager = asTenant($other['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($other['tenant_id'], ['product.manage'])));
    actingAs($otherManager)->getJson("/api/insurance/products/{$productId}", ['X-Tenant' => $other['tenant_id'], 'Accept' => 'application/json'])->assertNotFound();
});
