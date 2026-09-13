<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication;

use App\Models\User;
use App\Modules\Platform\Authentication\Actions\ResetUserPassword;
use App\Modules\Platform\Authentication\Actions\UpdateUserPassword;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify wiring for local and single-install sign-in. The tenant is already resolved when these routes run (ResolveTenant is in the
 * `web` group, before authentication), so the email is looked up within that tenant only (TenantScope + RLS). A successful sign-in binds
 * the session to the user's tenant. Registration is disabled (config/fortify.php). Zitadel OIDC replaces this LATER.
 */
final class AuthenticationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Fortify::authenticateUsing(static function (Request $request): ?User {
            if (! TenantContext::has()) {
                return null;
            }
            // Portal users (slice D9) only obtain API tokens; they never sign in to the staff web app.
            $user = User::query()->where('email', (string) $request->input(Fortify::username()))->where('status', 'active')->where('kind', 'staff')->first();

            return $user !== null && Hash::check((string) $request->input('password'), (string) $user->getAuthPassword()) ? $user : null;
        });
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::loginView(static fn () => Inertia::render('auth/Login', array_filter(['canResetPassword' => true, 'demoAccounts' => DemoAccounts::forSignIn()],
            static fn (mixed $value): bool => $value !== null)));
        Fortify::requestPasswordResetLinkView(static fn () => Inertia::render('auth/ForgotPassword'));
        Fortify::resetPasswordView(static fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'token' => (string) $request->route('token'), 'email' => (string) $request->query('email', ''),
        ]));
        Fortify::twoFactorChallengeView(static fn () => Inertia::render('auth/TwoFactorChallenge'));
        Fortify::confirmPasswordView(static fn () => Inertia::render('auth/ConfirmPassword'));

        // Reset links carry the plain email; the token row is keyed per tenant (User::getEmailForPasswordReset).
        ResetPassword::createUrlUsing(static fn (User $user, string $token): string => url(route('password.reset', ['token' => $token, 'email' => $user->email], false)));

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by((TenantContext::has() ? TenantContext::id() : '-').'|'.mb_strtolower((string) $request->input(Fortify::username())).'|'.$request->ip()));
        RateLimiter::for('two-factor', static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));

        Event::listen(Login::class, static function (Login $event): void {
            if ($event->user instanceof User && request()->hasSession()) {
                request()->session()->put('tenant_id', $event->user->tenant_id);
            }
        });
    }
}
