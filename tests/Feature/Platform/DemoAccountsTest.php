<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;

/**
 * Local convenience: the sign-in page lists the seeded accounts of the resolved tenant (`*.local` emails, seed password) so a developer can
 * fill the form in one click. Never outside the local environment, and never another tenant's users or real accounts.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->a = seedDemoTenant('tenant-a');
    $this->b = seedDemoTenant('tenant-b');
    seedRoleTemplates($this->a['tenant_id']);
    $this->addUser = function (string $tenantId, string $email, string $name, array $roleCodes): void {
        asTenant($tenantId, function () use ($tenantId, $email, $name, $roleCodes): void {
            DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $tenantId, 'email' => $email, 'name' => $name,
                'password' => Hash::make('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($roleCodes as $code) {
                DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                    'scope_type' => 'tenant', 'scope_id' => $tenantId]);
            }
        });
    };
    ($this->addUser)($this->a['tenant_id'], 'accountant@demo.local', 'Accountant', ['accountant']);
    ($this->addUser)($this->a['tenant_id'], 'admin@demo.local', 'Tenant Admin', ['tenant_admin', 'finance_manager']);
    ($this->addUser)($this->a['tenant_id'], 'real.person@example.com', 'Real Person', ['accountant']);
});

it('lists the tenant\'s seeded accounts with the seed password on the local sign-in page', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['erp.seed.admin_password' => 'Seed-pass-1']);

    get('/login', ['X-Tenant' => $this->a['tenant_id']])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Login')
        ->where('demoAccounts', [
            ['email' => 'accountant@demo.local', 'name' => 'Accountant', 'roles' => ['Accountant'], 'password' => 'Seed-pass-1'],
            ['email' => 'admin@demo.local', 'name' => 'Tenant Admin', 'roles' => ['Finance Manager', 'Tenant Admin'], 'password' => 'Seed-pass-1'],
        ]));

    get('/login', ['X-Tenant' => $this->b['tenant_id']])->assertInertia(fn (AssertableInertia $page) => $page->where('demoAccounts', []));
});

it('never offers accounts outside the local environment', function (): void {
    get('/login', ['X-Tenant' => $this->a['tenant_id']])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('demoAccounts'));
});
