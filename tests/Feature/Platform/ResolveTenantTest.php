<?php

declare(strict_types=1);

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/** Design §8.6.1: every web/api request runs in exactly one resolved tenant, established before authentication. */
beforeEach(function (): void {
    $currentTenant = fn (): array => ['tenant' => TenantContext::id()];
    Route::middleware('web')->get('/_test/tenant', $currentTenant);
    Route::middleware(['web', 'auth'])->get('/_test/protected', $currentTenant);
    Route::middleware('api')->get('/api/_test/tenant', $currentTenant);
});

it('runs a web request inside the tenant named by the request', function (): void {
    $tenantId = (string) Str::uuid7();

    getJson('/_test/tenant', ['X-Tenant' => $tenantId])
        ->assertOk()->assertJson(['tenant' => $tenantId]);

    expect(TenantContext::has())->toBeFalse();
});

it('resolves the tenant for api requests, which have no session', function (): void {
    $tenantId = (string) Str::uuid7();

    getJson('/api/_test/tenant', ['X-Tenant' => $tenantId])
        ->assertOk()->assertJson(['tenant' => $tenantId]);
});

it('rejects an api request without a tenant as a bad request', function (): void {
    getJson('/api/_test/tenant')->assertStatus(400);
});

it('rejects a malformed tenant id as a bad request instead of failing in the database', function (): void {
    getJson('/api/_test/tenant', ['X-Tenant' => 'not-a-uuid'])->assertStatus(400);
});

it('resolves the tenant before authentication runs', function (): void {
    getJson('/_test/protected')->assertStatus(400);
});

it('re-applies the tenant to the database session after a reconnect', function (): void {
    $tenantId = (string) Str::uuid7();

    TenantContext::run($tenantId, function () use ($tenantId): void {
        DB::reconnect();

        expect(DB::scalar("select current_setting('app.tenant_id', true)"))->toBe($tenantId);
    });
});
