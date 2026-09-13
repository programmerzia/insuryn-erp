<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Local and single-install sign-in (Fortify). Users are tenant-scoped (design §2.1, D-09): the tenant is resolved first (ResolveTenant runs
 * before authentication) and the email is looked up within that tenant only. Zitadel OIDC is LATER (CONTEXT.md).
 */
beforeEach(function (): void {
    $this->a = seedDemoTenant('tenant-a');
    $this->b = seedDemoTenant('tenant-b');
    $this->userIn = function (array $ctx, string $email, string $password, array $permissions = []): User {
        $id = userWithPermissions($ctx['tenant_id'], array_values(array_map(fn (mixed $p): string => (string) $p, $permissions)));

        return asTenant($ctx['tenant_id'], function () use ($id, $email, $password): User {
            DB::table('users')->where('id', $id)->update(['email' => $email, 'password' => Hash::make($password)]);

            return User::query()->findOrFail($id);
        });
    };
});

it('shows the login page for the resolved tenant', function (): void {
    $this->withoutVite();

    get('/login', ['X-Tenant' => $this->a['tenant_id']])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('tenant.slug', 'tenant-a'));
});

it('signs a user in within the resolved tenant and binds the session to that tenant', function (): void {
    $user = ($this->userIn)($this->a, 'officer@example.test', 'Correct-horse-9');

    post('/login', ['email' => 'officer@example.test', 'password' => 'Correct-horse-9'], ['X-Tenant' => $this->a['tenant_id']])
        ->assertRedirect(config('fortify.home'))
        ->assertSessionHas('tenant_id', $this->a['tenant_id']);

    asTenant($this->a['tenant_id'], fn () => assertAuthenticatedAs($user));
});

it('never signs in a user of another tenant with the same email', function (): void {
    ($this->userIn)($this->a, 'shared@example.test', 'Tenant-A-secret-1');
    ($this->userIn)($this->b, 'shared@example.test', 'Tenant-B-secret-1');
    $c = seedDemoTenant('tenant-c');

    post('/login', ['email' => 'shared@example.test', 'password' => 'Tenant-A-secret-1'], ['X-Tenant' => $this->b['tenant_id']])
        ->assertSessionHasErrors('email');
    assertGuest();

    post('/login', ['email' => 'shared@example.test', 'password' => 'Tenant-A-secret-1'], ['X-Tenant' => $c['tenant_id']])
        ->assertSessionHasErrors('email');
    assertGuest();
});

it('does not carry a signed-in session into another tenant', function (): void {
    ($this->userIn)($this->a, 'viewer@example.test', 'Correct-horse-9', ['accounting.view_journals']);
    post('/login', ['email' => 'viewer@example.test', 'password' => 'Correct-horse-9'], ['X-Tenant' => $this->a['tenant_id']]);

    get('/accounting/journals', ['X-Tenant' => $this->a['tenant_id']])->assertOk();
    get('/accounting/journals', ['X-Tenant' => $this->b['tenant_id']])->assertRedirect('/login'); // the session's user id does not exist in tenant B
});

it('redirects an unauthenticated browser request to the login page', function (): void {
    get('/accounting/journals', ['X-Tenant' => $this->a['tenant_id']])->assertRedirect('/login');
    get('/accounting/trial-balance', ['X-Tenant' => $this->a['tenant_id']])->assertRedirect('/login');
});

it('refuses the trial balance to a user without reports.financial', function (): void {
    $user = ($this->userIn)($this->a, 'clerk@example.test', 'Correct-horse-9', ['accounting.view_journals']);

    actingAs($user)->get('/accounting/trial-balance', ['X-Tenant' => $this->a['tenant_id']])->assertForbidden();
});

it('asks for the second factor when two-factor authentication is confirmed', function (): void {
    $user = ($this->userIn)($this->a, 'cfo@example.test', 'Correct-horse-9');
    asTenant($this->a['tenant_id'], fn () => $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one'])), 'two_factor_confirmed_at' => now()])->save());

    post('/login', ['email' => 'cfo@example.test', 'password' => 'Correct-horse-9'], ['X-Tenant' => $this->a['tenant_id']])
        ->assertRedirect('/two-factor-challenge');
    assertGuest();
});

it('keeps password reset tokens within the tenant that issued them', function (): void {
    Notification::fake();
    $userA = ($this->userIn)($this->a, 'shared@example.test', 'Tenant-A-secret-1');
    ($this->userIn)($this->b, 'shared@example.test', 'Tenant-B-secret-1');

    post('/forgot-password', ['email' => 'shared@example.test'], ['X-Tenant' => $this->a['tenant_id']])->assertSessionHasNoErrors();
    $token = null;
    Notification::assertSentTo($userA, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    $reset = ['token' => $token, 'email' => 'shared@example.test', 'password' => 'Brand-new-pass-7', 'password_confirmation' => 'Brand-new-pass-7'];

    post('/reset-password', $reset, ['X-Tenant' => $this->b['tenant_id']])->assertSessionHasErrors('email');
    expect(asTenant($this->b['tenant_id'], fn () => Hash::check('Tenant-B-secret-1', (string) DB::table('users')->where('email', 'shared@example.test')->value('password'))))->toBeTrue();

    post('/reset-password', $reset, ['X-Tenant' => $this->a['tenant_id']])->assertSessionHasNoErrors();
    expect(asTenant($this->a['tenant_id'], fn () => Hash::check('Brand-new-pass-7', (string) DB::table('users')->where('email', 'shared@example.test')->value('password'))))->toBeTrue();
});

it('has registration disabled', function (): void {
    get('/register', ['X-Tenant' => $this->a['tenant_id']])->assertNotFound();
});
