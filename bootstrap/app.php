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
        $middleware->web(append: [ResolveTenant::class, HandleInertiaRequests::class, \App\Http\Preview\PreviewJournal::class]);
        $middleware->alias(['moves-money' => \App\Http\Preview\MovesMoney::class, 'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class, 'producer-portal' => \App\Http\Portal\EnsureProducerPortal::class]);
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
        // API and JSON requests get the machine-readable body; browser (Inertia) forms go back to the form with the reason as a `form` error.
        $wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        // Browser forms get the reason written for people (UX brief §4); JSON keeps the domain message.
        $backToForm = fn (Request $request, string $message, string $reason) => back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))
            ->withErrors(['form' => \App\Http\Feedback\ReasonMessages::forPeople($reason, $message), 'reason' => $reason]);
        $exceptions->render(fn (PermissionDenied $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage(), 'reason' => 'PERMISSION_DENIED', 'permission' => $e->permission], 403)
            : ($request->isMethod('GET') ? response('You do not have permission for this page.', 403) : $backToForm($request, 'You do not have permission for this action.', 'PERMISSION_DENIED')));
        $exceptions->render(fn (SodViolation $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode, 'rule' => $e->ruleCode], 403)
            : $backToForm($request, $e->getMessage(), $e->reasonCode));
        // Follow-up H2: risk schema problems name each field by its label, per field (`risk_inputs.<key>`), in the user's language.
        $exceptions->render(fn (\App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid $e, Request $request) => $wantsJson($request)
            ? response()->json(\App\Http\Feedback\RiskProblems::json($e, \App\Http\Feedback\RiskProblems::locale($request)), 422)
            : back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->withErrors(\App\Http\Feedback\RiskProblems::formErrorsFor($e, \App\Http\Feedback\RiskProblems::locale($request))));
        $exceptions->render(fn (AccountingException|BusinessRuleViolation|ApprovalException|NumberingException $e, Request $request) => $wantsJson($request)
            // Slice 2.1b: a period refusal carries its detail (the pending documents that hold the lock).
            ? response()->json(['message' => $e->getMessage(), 'reason' => $e->reasonCode]
                + ($e instanceof \App\Modules\Accounting\Exceptions\PeriodTransitionException && $e->details !== [] ? ['details' => $e->details] : []), 422)
            : $backToForm($request, $e->getMessage(), $e->reasonCode));
    })->create();
