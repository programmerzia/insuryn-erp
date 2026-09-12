<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/** D-09: users are tenant-scoped with UUIDv7 keys, and the tenant is resolved before authentication. */
beforeEach(function (): void {
    $this->a = seedDemoTenant('users-a');
    $this->b = seedDemoTenant('users-b');
    Route::middleware(['web', 'auth'])->get('/_test/me', fn (): array => ['user' => Auth::id()]);
});

it('creates users with UUIDv7 keys inside the current tenant', function (): void {
    $user = asTenant($this->a['tenant_id'], fn (): User => User::factory()->create());

    expect(Str::isUuid($user->id))->toBeTrue()
        ->and($user->getIncrementing())->toBeFalse()
        ->and($user->tenant_id)->toBe($this->a['tenant_id']);
});

it('never finds a user of another tenant', function (): void {
    $user = asTenant($this->a['tenant_id'], fn (): User => User::factory()->create());

    expect(asTenant($this->b['tenant_id'], fn (): ?User => User::query()->find($user->id)))->toBeNull()
        ->and(asTenant($this->a['tenant_id'], fn (): ?User => User::query()->find($user->id))?->id)->toBe($user->id);
});

it('authenticates a tenant user on a protected route', function (): void {
    $user = asTenant($this->a['tenant_id'], fn (): User => User::factory()->create());

    actingAs($user)->getJson('/_test/me', ['X-Tenant' => $this->a['tenant_id']])
        ->assertOk()->assertJson(['user' => $user->id]);
});

it('orders tenant resolution after the session starts and before authentication', function (): void {
    app(HttpKernel::class); // the HTTP kernel registers middleware groups and priority on the router
    $router = app(Router::class);
    $route = $router->getRoutes()->match(request()->create('/_test/me'));
    $middleware = array_values(array_map(
        fn (mixed $entry): string => is_string($entry) ? explode(':', $entry)[0] : '',
        $router->gatherRouteMiddleware($route),
    ));

    $position = function (string $class) use ($middleware): int {
        $index = array_search($class, $middleware, true);
        expect($index)->not->toBeFalse("{$class} is not in the route's middleware");

        return (int) $index;
    };

    expect($position(StartSession::class))->toBeLessThan($position(ResolveTenant::class))
        ->and($position(ResolveTenant::class))->toBeLessThan($position(Authenticate::class));
});
