<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap audit GA-02: the hierarchy tree opened with a 500 when the tenant had no compensation scheme (the nonlife and Part A demo tenants), because
 * the levels query compared the uuid column with ''. The page opens with no scheme and no levels, and a scheme parameter that is not a uuid is ignored.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail($this->world['admin']));
});

it('opens the hierarchy tree when the tenant has no compensation scheme', function (): void {
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('compensation_schemes')->count()))->toBe(0);

    actingAs($this->admin)->get('/distribution/hierarchy', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/hierarchy/Index')->where('scheme', null)->where('schemes', [])->where('levels', [])
        ->where('nodes', fn (Illuminate\Support\Collection $nodes): bool => $nodes->contains(fn ($n): bool => $n['code'] === 'AG-001')));
});

it('ignores a scheme parameter that is not a scheme id', function (): void {
    actingAs($this->admin)->get('/distribution/hierarchy?scheme=not-a-uuid', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('scheme', null)->where('levels', []));
});
