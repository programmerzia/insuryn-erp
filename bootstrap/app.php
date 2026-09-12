<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ResolveTenant::class, HandleInertiaRequests::class]);
        $middleware->api(append: [ResolveTenant::class]);
        // Tenant must be known before auth loads a (tenant-scoped) user, and after the session exists.
        // Anchor on StartSession: Authenticate itself is not in the priority list, so anchoring on it
        // would silently append ResolveTenant last.
        $middleware->appendToPriorityList(StartSession::class, ResolveTenant::class);
        // No login page yet (Zitadel OIDC sign-in is not built): no guest redirect; guests get 401 (see withExceptions).
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // No login page exists yet (Zitadel OIDC sign-in is not built): guests get 401 instead of a
        // redirect to a missing login route.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->expectsJson()
            ? null
            : response('Unauthenticated.', 401));
    })->create();
