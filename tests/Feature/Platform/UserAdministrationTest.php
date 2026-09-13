<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Administration\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * Phase 2.0 carry-over (exit checklist "User and role administration"): the Tenant Admin invites users, assigns roles by tenant, entity or
 * branch over RoleAssignmentService (so design §7.3 SoD holds at assignment), removes roles, and deactivates users. ASSUMPTIONS (conservative):
 * nobody deactivates themselves, and the tenant always keeps one active user who can manage users and one who can manage roles.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->role = fn (string $code): string => ($this->in)(fn (): string => (string) DB::table('roles')->where('code', $code)->value('id'));
    $this->person = function (string $name, array $roleCodes = [], string $status = 'active'): User {
        return ($this->in)(function () use ($name, $roleCodes, $status): User {
            DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
                'password' => 'x', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($roleCodes as $code) {
                DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => ($this->role)($code), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
            }

            return User::query()->findOrFail($id);
        });
    };
    $this->admin = ($this->person)('Nadia Admin', ['tenant_admin']);
    $this->rolesOf = fn (User $user): array => ($this->in)(fn (): array => DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
        ->where('ur.user_id', $user->id)->orderBy('r.code')->get(['r.code', 'ur.scope_type', 'ur.scope_id'])->map(fn (object $row): array => (array) $row)->all());
});

it('opens the user screens only for people who manage users', function (): void {
    $accountant = ($this->person)('Karim Accountant', ['accountant']);

    actingAs($accountant)->get('/admin/users', $this->headers)->assertForbidden();
    actingAs($accountant)->get("/admin/users/{$accountant->id}", $this->headers)->assertForbidden();
    actingAs($accountant)->post('/admin/users', ['name' => 'X', 'email' => 'x@example.test'], $this->headers)->assertSessionHasErrors('form');

    actingAs($this->admin)->get('/admin/users', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/users/Index')
        ->where('users', fn ($users): bool => in_array(['name' => 'Karim Accountant', 'email' => 'karim-accountant@example.test', 'status' => 'active', 'roles' => ['Accountant']],
            array_map(fn (array $u): array => array_diff_key($u, ['id' => true]), json_decode((string) json_encode($users), true)), true)));
});

it('invites a user, who sets a password from the emailed link and signs in', function (): void {
    Notification::fake();

    actingAs($this->admin)->post('/admin/users', ['name' => 'Farhana Rahman', 'email' => 'farhana@example.test'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation sent to farhana@example.test.');
    $invited = ($this->in)(fn (): User => User::query()->where('email', 'farhana@example.test')->firstOrFail());
    expect($invited->status)->toBe('active')
        ->and(($this->in)(fn () => DB::table('audit_events')->where('action', 'user.invited')->where('object_id', $invited->id)->count()))->toBe(1);

    $url = null;
    Notification::assertSentTo($invited, UserInvitation::class, function (UserInvitation $notification) use ($invited, &$url): bool {
        $url = $notification->url($invited);

        return true;
    });
    expect($url)->toContain('/reset-password/')->toContain('email=farhana%40example.test');
    $token = (string) Str::between((string) $url, '/reset-password/', '?');

    auth()->logout();
    post('/reset-password', ['token' => $token, 'email' => 'farhana@example.test', 'password' => 'Fresh-start-42', 'password_confirmation' => 'Fresh-start-42'], $this->headers)
        ->assertSessionHasNoErrors();
    post('/login', ['email' => 'farhana@example.test', 'password' => 'Fresh-start-42'], $this->headers)->assertRedirect(config('fortify.home'));

    actingAs($this->admin)->post('/admin/users', ['name' => 'Again', 'email' => 'farhana@example.test'], $this->headers)
        ->assertSessionHasErrors(['email' => 'A user with this email already exists.']);
});

it('assigns roles by tenant, entity or branch and removes them', function (): void {
    $officer = ($this->person)('Rafiq Officer');

    actingAs($this->admin)->post("/admin/users/{$officer->id}/roles", ['role_id' => ($this->role)('branch_officer'), 'scope_type' => 'branch', 'scope_id' => $this->ctx['branch_id']], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Branch Officer added.');
    actingAs($this->admin)->post("/admin/users/{$officer->id}/roles", ['role_id' => ($this->role)('auditor'), 'scope_type' => 'tenant'], $this->headers)
        ->assertSessionHasErrors(['form' => 'An auditor stays read-only, so Rafiq Officer cannot hold the Auditor role together with cover_note.issue.']); // the first write permission of the role (slices R8 and R6 added document.generate and cover_note.issue)
    actingAs($this->admin)->post("/admin/users/{$officer->id}/roles", ['role_id' => ($this->role)('branch_officer'), 'scope_type' => 'branch', 'scope_id' => $this->ctx['branch_id']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Rafiq Officer already has Branch Officer there.']);
    actingAs($this->admin)->post("/admin/users/{$officer->id}/roles", ['role_id' => ($this->role)('accountant'), 'scope_type' => 'branch', 'scope_id' => (string) Str::uuid7()], $this->headers)
        ->assertSessionHasErrors('scope_id');

    expect(($this->rolesOf)($officer))->toBe([['code' => 'branch_officer', 'scope_type' => 'branch', 'scope_id' => $this->ctx['branch_id']]]);

    actingAs($this->admin)->get("/admin/users/{$officer->id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/users/Show')
        ->where('user.name', 'Rafiq Officer')->where('assignments.0.role', 'Branch Officer')->where('assignments.0.scope', fn (string $scope): bool => str_starts_with($scope, 'Branch '))
        ->where('timeline.0.sentence', 'Given Branch Officer for branch HO by Nadia Admin')->has('roles')->has('branches')->has('entities'));

    actingAs($this->admin)->post("/admin/users/{$officer->id}/roles/remove", ['role_id' => ($this->role)('branch_officer'), 'scope_type' => 'branch', 'scope_id' => $this->ctx['branch_id']], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Branch Officer removed.');
    expect(($this->rolesOf)($officer))->toBe([])
        ->and(($this->in)(fn () => DB::table('audit_events')->where('action', 'user_role.revoked')->where('object_id', $officer->id)->count()))->toBe(1);
});

it('refuses role combinations segregation of duties forbids, and says which', function (): void {
    $manager = ($this->person)('Selim Manager', ['finance_manager']);

    actingAs($this->admin)->post("/admin/users/{$manager->id}/roles", ['role_id' => ($this->role)('tenant_admin'), 'scope_type' => 'tenant'], $this->headers)
        ->assertSessionHasErrors(['form' => 'Selim Manager cannot hold platform.manage_roles together with accounting.approve_journal (segregation of duties). Remove one of the roles first.']);
    expect(($this->rolesOf)($manager))->toBe([['code' => 'finance_manager', 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]]);
});

it('deactivates and reactivates users, signing them out, but never locks the tenant out', function (): void {
    $clerk = ($this->person)('Mina Clerk', ['branch_officer']);
    ($this->in)(fn () => DB::table('sessions')->insert(['id' => 'clerk-session', 'user_id' => $clerk->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]));

    actingAs($this->admin)->post("/admin/users/{$clerk->id}/deactivate", [], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Mina Clerk can no longer sign in.');
    expect(($this->in)(fn () => [DB::table('users')->where('id', $clerk->id)->value('status'), DB::table('sessions')->where('user_id', $clerk->id)->count()]))->toBe(['inactive', 0]);
    auth()->logout();
    post('/login', ['email' => 'mina-clerk@example.test', 'password' => 'x'], $this->headers)->assertSessionHasErrors('email');

    actingAs($this->admin)->post("/admin/users/{$clerk->id}/reactivate", [], $this->headers)->assertSessionHas('status', 'Mina Clerk can sign in again.');
    expect(($this->in)(fn () => DB::table('users')->where('id', $clerk->id)->value('status')))->toBe('active');

    actingAs($this->admin)->post("/admin/users/{$this->admin->id}/deactivate", [], $this->headers)
        ->assertSessionHasErrors(['form' => 'You cannot deactivate your own account. Ask another administrator.']);

    actingAs($this->admin)->post("/admin/users/{$this->admin->id}/roles/remove", ['role_id' => ($this->role)('tenant_admin'), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Nadia Admin is the last active user who can manage users. Give someone else that permission first.']);
    expect(($this->rolesOf)($this->admin))->toBe([['code' => 'tenant_admin', 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]]);

    $deputy = ($this->person)('Deputy Admin', ['tenant_admin']);
    actingAs($deputy)->post("/admin/users/{$this->admin->id}/deactivate", [], $this->headers)->assertSessionHasNoErrors();
    actingAs($deputy)->post("/admin/users/{$deputy->id}/roles/remove", ['role_id' => ($this->role)('tenant_admin'), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Deputy Admin is the last active user who can manage users. Give someone else that permission first.']);
});

it('resends an invitation', function (): void {
    Notification::fake();
    $invited = ($this->person)('Late Joiner');

    actingAs($this->admin)->post("/admin/users/{$invited->id}/invitation", [], $this->headers)->assertSessionHas('status', 'Invitation sent to late-joiner@example.test.');
    Notification::assertSentTo($invited, UserInvitation::class);
});

it('keeps users of another tenant out of reach', function (): void {
    $other = seedDemoTenant('other');
    $stranger = asTenant($other['tenant_id'], function () use ($other): string {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $other['tenant_id'], 'email' => 's@example.test', 'name' => 'Stranger', 'password' => 'x', 'status' => 'active']);

        return $id;
    });

    actingAs($this->admin)->get("/admin/users/{$stranger}", $this->headers)->assertNotFound();
    actingAs($this->admin)->post("/admin/users/{$stranger}/deactivate", [], $this->headers)->assertNotFound();
});
