<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Preferences\UserPreferences;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\putJson;

/**
 * UX brief §4 "Every list, inspector width, column set, density and theme persists per user" (slice U2): preferences live server-side per
 * user in user_preferences, are shared with every page, and set the theme and density on <html> before the first paint.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals'])));
    $this->other = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals'])));
});

it('refuses guests', function (): void {
    putJson('/preferences/theme', ['value' => 'dark'], $this->headers)->assertUnauthorized();
});

it('stores preferences per user, merges keys and shares them with every page', function (): void {
    actingAs($this->user)->putJson('/preferences/theme', ['value' => 'dark'], $this->headers)->assertNoContent();
    actingAs($this->user)->putJson('/preferences/density', ['value' => 'comfortable'], $this->headers)->assertNoContent();
    actingAs($this->user)->putJson('/preferences/splits.receipts', ['value' => 420], $this->headers)->assertNoContent();
    actingAs($this->user)->putJson('/preferences/sidebar_collapsed', ['value' => true], $this->headers)->assertNoContent();

    expect(asTenant($this->ctx['tenant_id'], fn () => app(UserPreferences::class)->of($this->user->id)))
        ->toMatchArray(['theme' => 'dark', 'density' => 'comfortable', 'splits' => ['receipts' => 420], 'sidebar_collapsed' => true]);
    expect(asTenant($this->ctx['tenant_id'], fn () => app(UserPreferences::class)->of($this->other->id)))->toMatchArray(['theme' => 'system', 'density' => 'compact']);
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('user_preferences')->count()))->toBe(1);

    actingAs($this->user)->get('/accounting/journals', $this->headers)
        ->assertSee('data-theme="dark"', false)->assertSee('data-density="comfortable"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preferences.theme', 'dark')->where('preferences.splits.receipts', 420));
    actingAs($this->other)->get('/accounting/journals', $this->headers)->assertDontSee('data-theme=', false);
});

it('validates each preference and never stores what it does not know', function (string $key, mixed $value): void {
    actingAs($this->user)->putJson("/preferences/{$key}", ['value' => $value], $this->headers)->assertUnprocessable();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('user_preferences')->count()))->toBe(0);
})->with([
    'unknown key' => ['favourite_colour', 'red'],
    'theme' => ['theme', 'purple'],
    'density' => ['density', 'huge'],
    'split too narrow' => ['splits.receipts', 40],
    'nine tabs' => ['tabs', array_fill(0, 9, ['href' => '/policies', 'title' => 'Policies'])],
    'tab outside the app' => ['tabs', [['href' => 'https://example.com', 'title' => 'x']]],
    'branch not a uuid' => ['branch_id', 'head office'],
    'nested key name' => ['tables.bad key', ['columns' => []]],
]);

it('keeps up to eight pinned tabs', function (): void {
    $tabs = array_map(fn (int $i): array => ['href' => "/policies/{$i}", 'title' => "POL-{$i}"], range(1, 8));
    actingAs($this->user)->putJson('/preferences/tabs', ['value' => $tabs], $this->headers)->assertNoContent();

    expect(asTenant($this->ctx['tenant_id'], fn () => app(UserPreferences::class)->of($this->user->id)['tabs']))->toHaveCount(8);
});
