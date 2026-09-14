<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Phase 2.0 carry-over: roles are "seeded templates per tenant, editable" (design §7.2). Changing a role's permissions changes what every holder
 * holds, so the same checks as assignment apply to each holder (design §7.3: user-level SoD conflicts, auditor stays read-only), and the tenant
 * always keeps an active user who can manage users and one who can manage roles.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->role = fn (string $code): string => ($this->in)(fn (): string => (string) DB::table('roles')->where('code', $code)->value('id'));
    $this->person = function (string $name, array $roleCodes): User {
        return ($this->in)(function () use ($name, $roleCodes): User {
            DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
                'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($roleCodes as $code) {
                DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => ($this->role)($code), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
            }

            return User::query()->findOrFail($id);
        });
    };
    $this->admin = ($this->person)('Nadia Admin', ['tenant_admin']);
    $this->permissionsOf = fn (string $roleCode): array => ($this->in)(fn (): array => DB::table('role_permissions')->where('role_id', ($this->role)($roleCode))
        ->orderBy('permission_code')->pluck('permission_code')->all());
});

it('lists roles with their permissions and holders, only for people who manage roles', function (): void {
    ($this->person)('Karim Accountant', ['accountant']);

    actingAs(($this->person)('Branch Person', ['branch_officer']))->get('/admin/roles', $this->headers)->assertForbidden();
    actingAs($this->admin)->get('/admin/roles', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/roles/Index')
        ->where('roles', fn ($roles): bool => in_array(['code' => 'accountant', 'name' => 'Accountant', 'permissions' => 6, 'holders' => 1],
            array_map(fn (array $r): array => array_diff_key($r, ['id' => true]), json_decode((string) json_encode($roles), true)), true)));

    actingAs($this->admin)->get('/admin/roles/'.($this->role)('accountant'), $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/roles/Show')
        ->where('role.name', 'Accountant')->where('granted', ['accounting.create_manual_journal', 'accounting.view_journals', 'bank.import', 'bank.match', 'commission.pay', 'receipt.allocate'])
        ->where('holders.0.name', 'Karim Accountant')->where('catalogue', fn ($groups): bool => in_array('Accounting', array_column(json_decode((string) json_encode($groups), true), 'label'), true)));
});

it('creates a role and changes its permissions, recording what changed', function (): void {
    actingAs($this->admin)->post('/admin/roles', ['name' => 'Collections Supervisor'], $this->headers)->assertSessionHasNoErrors();
    $roleId = ($this->role)('collections_supervisor');
    expect($roleId)->not->toBe('');
    actingAs($this->admin)->post('/admin/roles', ['name' => 'Collections supervisor'], $this->headers)->assertSessionHasErrors(['name' => 'A role with this name already exists.']);

    actingAs($this->admin)->put("/admin/roles/{$roleId}", ['permissions' => ['receipt.create', 'receipt.allocate']], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Collections Supervisor updated: 2 permissions added.');
    actingAs($this->admin)->put("/admin/roles/{$roleId}", ['permissions' => ['receipt.allocate', 'no.such_permission']], $this->headers)->assertSessionHasErrors('permissions.1');
    actingAs($this->admin)->put("/admin/roles/{$roleId}", ['permissions' => ['receipt.allocate']], $this->headers)->assertSessionHas('status', 'Collections Supervisor updated: 1 permission removed.');

    expect(($this->permissionsOf)('collections_supervisor'))->toBe(['receipt.allocate'])
        ->and(($this->in)(fn () => json_decode((string) DB::table('audit_events')->where('action', 'role.permissions_changed')->orderByDesc('occurred_at')->orderByDesc('id')->value('after'), true)))
        ->toBe(['added' => [], 'removed' => ['receipt.create']]);
});

it('refuses a permission change that would give a holder a forbidden combination', function (): void {
    ($this->person)('Selim Manager', ['finance_manager', 'branch_officer']);

    actingAs($this->admin)->put('/admin/roles/'.($this->role)('branch_officer'), ['permissions' => ['policy.create', 'policy.issue', 'receipt.create', 'party.manage', 'platform.manage_roles']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Selim Manager holds this role and cannot hold platform.manage_roles together with accounting.approve_journal (segregation of duties).']);
    ($this->person)('Ayesha Auditor', ['auditor']);
    actingAs($this->admin)->put('/admin/roles/'.($this->role)('auditor'), ['permissions' => ['audit.view', 'receipt.create']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Auditors stay read-only, so the Auditor role cannot include receipt.create.']);

    expect(($this->permissionsOf)('branch_officer'))->toBe(['cover_note.issue', 'document.generate', 'party.manage', 'policy.create', 'policy.issue', 'quotation.create', 'receipt.create', 'renewal.manage']); // + A-101 (slice R8), A-83 (R4), A-94 (R6), A-126 (R9)
});

it('never removes the last way to manage users or roles', function (): void {
    actingAs($this->admin)->put('/admin/roles/'.($this->role)('tenant_admin'), ['permissions' => ['platform.manage_users']], $this->headers)
        ->assertSessionHasErrors(['form' => 'Nobody active would be left who can manage roles. Give that permission to someone else first.']);
    expect(($this->permissionsOf)('tenant_admin'))->toBe(['document.manage_templates', 'platform.manage_approvals', 'platform.manage_roles', 'platform.manage_users', 'underwriting.manage_limits']); // + A-54 (fix F3), A-101 (slice R8), A-87 (R5)
});

it('deletes a role only when nobody holds it', function (): void {
    ($this->person)('Karim Accountant', ['accountant']);

    actingAs($this->admin)->delete('/admin/roles/'.($this->role)('accountant'), [], $this->headers)
        ->assertSessionHasErrors(['form' => 'Accountant is held by 1 user. Remove it from them first.']);
    actingAs($this->admin)->delete('/admin/roles/'.($this->role)('cfo'), [], $this->headers)->assertRedirect('/admin/roles')->assertSessionHas('status', 'CFO deleted.');
    expect(($this->role)('cfo'))->toBe('');
});
