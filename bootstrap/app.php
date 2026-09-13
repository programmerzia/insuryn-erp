<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\ResolveTenant;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Platform\Approvals\ApprovalException;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\Exceptions\NumberingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
        // Browser guests go to the Fortify login page; JSON/API guests get 401 (shouldRenderJsonWhen below).
        $middleware->redirectGuestsTo(fn (): string => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Business rule outcomes map to HTTP: missing permission or segregation of duties → 403,
        // a broken business rule → 422, both with the machine-readable reason code.
        $exceptions->render(fn (PermissionDenied $e) => response()->json(['message' => $e->getMessage(), 'reason' => 'PERMISSION_DENIED', 'permission' => $e->permission], 403));
        $exceptions->render(fn (SodViolation $e) => response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode, 'rule' => $e->ruleCode], 403));
        $exceptions->render(fn (AccountingException|BusinessRuleViolation|ApprovalException|NumberingException $e) => response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode], 422));
    })->create();
