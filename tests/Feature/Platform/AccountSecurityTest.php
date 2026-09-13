<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** The signed-in user's own security settings: change password, and turn two-factor authentication on (with confirmation) or off. */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = asTenant($this->ctx['tenant_id'], function (): User {
        $id = userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals']);
        DB::table('users')->where('id', $id)->update(['password' => Hash::make('Current-pass-1')]);

        return User::query()->findOrFail($id);
    });
    $this->fresh = fn (): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail($this->user->id));
});

it('shows the security page to a signed-in user only', function (): void {
    $this->withoutVite();

    get('/account/security', $this->headers)->assertRedirect('/login');
    actingAs($this->user)->get('/account/security', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('account/Security')->where('twoFactor.enabled', false)->where('twoFactor.confirmed', false));
});

it('changes the password only with the current password', function (): void {
    actingAs($this->user)->put('/user/password', ['current_password' => 'wrong', 'password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'], $this->headers)
        ->assertSessionHasErrorsIn('updatePassword', 'current_password');
    expect(Hash::check('Current-pass-1', (string) ($this->fresh)()->getAuthPassword()))->toBeTrue();

    actingAs($this->user)->put('/user/password', ['current_password' => 'Current-pass-1', 'password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'], $this->headers)
        ->assertSessionHasNoErrors();
    expect(Hash::check('Brand-new-pass-2', (string) ($this->fresh)()->getAuthPassword()))->toBeTrue();
});

it('enables two-factor authentication after password confirmation and a valid code, then disables it', function (): void {
    actingAs($this->user)->post('/user/two-factor-authentication', [], $this->headers)->assertRedirect('/user/confirm-password');

    $confirmed = ['auth.password_confirmed_at' => time()];
    actingAs($this->user)->withSession($confirmed)->post('/user/two-factor-authentication', [], $this->headers)->assertSessionHasNoErrors();
    $secret = decrypt((string) ($this->fresh)()->two_factor_secret);
    expect(($this->fresh)()->two_factor_confirmed_at)->toBeNull();

    actingAs($this->user)->withSession($confirmed)->post('/user/confirmed-two-factor-authentication', ['code' => '000000'], $this->headers)
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');
    actingAs($this->user)->withSession($confirmed)->post('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA())->getCurrentOtp($secret)], $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->fresh)()->two_factor_confirmed_at)->not->toBeNull();

    $this->withoutVite();
    actingAs(($this->fresh)())->withSession($confirmed)->getJson('/user/two-factor-recovery-codes', $this->headers)->assertOk()->assertJsonCount(8);
    actingAs(($this->fresh)())->get('/account/security', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.confirmed', true));

    actingAs(($this->fresh)())->withSession($confirmed)->delete('/user/two-factor-authentication', [], $this->headers)->assertSessionHasNoErrors();
    expect(($this->fresh)()->two_factor_secret)->toBeNull();
});
