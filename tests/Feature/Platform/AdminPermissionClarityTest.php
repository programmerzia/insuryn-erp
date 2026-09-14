<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Authentication\Actions\ResetUserPassword;
use App\Modules\Platform\Authorization\PermissionCatalogue;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Gap fix GA-22: an administrator could not tell what a tick grants — raw group names ("Cover_note"), no explanation, no warning when two ticked
 * permissions break a segregation rule — "Resend invitation" showed for everyone, and inviting could not give a role.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->role = fn (string $code): string => ($this->in)(fn (): string => (string) DB::table('roles')->where('code', $code)->value('id'));
    $this->admin = ($this->in)(function (): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'nadia@example.test', 'name' => 'Nadia Admin', 'password' => 'x', 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => ($this->role)('tenant_admin'), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
});

it('labels every permission in the catalogue with what it grants, under readable group names', function (): void {
    foreach (PermissionsSeeder::PERMISSIONS as $code) {
        expect(PermissionCatalogue::help($code))->not->toBeNull("{$code} has no help")
            ->and(PermissionCatalogue::label($code))->not->toContain('_');
        expect(array_key_exists(explode('.', $code, 2)[0], PermissionCatalogue::GROUPS))->toBeTrue("{$code} has no group label");
    }

    actingAs($this->admin)->get('/admin/roles/'.($this->role)('branch_manager'), $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/roles/Show')
        ->where('catalogue', function ($groups): bool {
            $groups = json_decode((string) json_encode($groups), true);
            $labels = array_column($groups, 'label');
            $coverNotes = $groups[array_search('Cover notes', $labels, true)];

            return ! in_array('Cover_note', $labels, true) && $labels[0] === 'Quotes' && $coverNotes['permissions'][0] === ['code' => 'cover_note.cancel', 'label' => 'Cancel cover notes', 'help' => 'Cancel a cover note.'];
        })
        ->where('sodRules', fn ($rules): bool => in_array(['a' => 'receipt.refund_request', 'b' => 'receipt.refund_release', 'mode' => 'block'], json_decode((string) json_encode($rules), true), true)
            && ! in_array('claim.reserve', array_column(json_decode((string) json_encode($rules), true), 'a'), true))); // object-level rules are checked per record, not on a role
});

it('offers to resend an invitation only while it is open, and gives a first role when inviting', function (): void {
    actingAs($this->admin)->get('/admin/users', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('roles', fn ($roles): bool => count($roles) === 9));

    actingAs($this->admin)->post('/admin/users', ['name' => 'Rafiq Officer', 'email' => 'rafiq@example.test', 'role_id' => ($this->role)('branch_officer')], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation sent to rafiq@example.test. Branch Officer given for the whole organisation.');
    $rafiq = ($this->in)(fn (): User => User::query()->where('email', 'rafiq@example.test')->firstOrFail());
    actingAs($this->admin)->get("/admin/users/{$rafiq->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('user.invitation_pending', true)->where('assignments.0.role', 'Branch Officer')
        ->where('timeline', fn ($entries): bool => array_filter(array_column(json_decode((string) json_encode($entries), true), 'sentence'), fn (string $s): bool => str_starts_with($s, 'Given Branch Officer')) !== []));

    // Setting the password accepts the invitation.
    ($this->in)(fn () => app(ResetUserPassword::class)->reset($rafiq, ['password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9']));
    actingAs($this->admin)->get("/admin/users/{$rafiq->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('user.invitation_pending', false));
    actingAs($this->admin)->post("/admin/users/{$rafiq->id}/invitation", [], $this->headers)->assertSessionHasNoErrors(); // sent again on purpose: open once more
    actingAs($this->admin)->get("/admin/users/{$rafiq->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('user.invitation_pending', true));

    // A user who never had an invitation (seeded) is not offered one; inviting without a role still works.
    actingAs($this->admin)->get("/admin/users/{$this->admin->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('user.invitation_pending', false));
    actingAs($this->admin)->post('/admin/users', ['name' => 'Lima', 'email' => 'lima@example.test', 'role_id' => ''], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation sent to lima@example.test.');
});
