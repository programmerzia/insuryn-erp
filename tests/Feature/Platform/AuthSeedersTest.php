<?php

declare(strict_types=1);

use App\Modules\Platform\Authorization\RoleTemplates;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Design §7.2 role templates seeded per tenant (RolesSeeder) and one admin per tenant for local and single-install use (AdminUserSeeder). */
beforeEach(function (): void {
    $this->demo = seedDemoTenant('demo');
    config(['erp.seed.admin_password' => 'Seeded-Pass-42']);
});

/** @return list<string> role codes held by the tenant's admin */
function adminRoles(string $tenantId, string $email): array
{
    return array_values(asTenant($tenantId, fn (): array => DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')
        ->where('u.email', $email)->orderBy('r.code')->pluck('r.code')->map(fn ($c): string => (string) $c)->all()));
}

it('seeds every §7.2 role template with its permissions, idempotently', function (): void {
    (new RolesSeeder())->run();
    (new RolesSeeder())->run();

    asTenant($this->demo['tenant_id'], function (): void {
        expect(DB::table('roles')->orderBy('code')->pluck('code')->all())->toBe(['accountant', 'auditor', 'branch_manager', 'branch_officer', 'cfo', 'claims_manager', 'claims_officer', 'finance_manager', 'tenant_admin']);
        foreach (RoleTemplates::all() as $code => $template) {
            $permissions = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.code', $code)->orderBy('rp.permission_code')->pluck('rp.permission_code')->all();
            $expected = array_values(array_unique($template['permissions']));
            sort($expected);
            expect($permissions)->toBe($expected);
        }
        expect(DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.code', 'tenant_admin')->where('rp.permission_code', 'like', 'accounting.%')->count())->toBe(0);
    });
});

it('creates one tenant admin per tenant with the configured password, and only in local the finance and claims manager roles', function (): void {
    $other = seedDemoTenant('acme');
    (new RolesSeeder())->run();
    (new AdminUserSeeder())->run();
    (new AdminUserSeeder())->run();

    expect(adminRoles($this->demo['tenant_id'], 'admin@demo.local'))->toBe(['tenant_admin'])
        ->and(adminRoles($other['tenant_id'], 'admin@acme.local'))->toBe(['tenant_admin'])
        ->and(asTenant($this->demo['tenant_id'], fn () => DB::table('users')->where('email', 'admin@demo.local')->count()))->toBe(1)
        ->and(asTenant($this->demo['tenant_id'], fn () => Hash::check('Seeded-Pass-42', (string) DB::table('users')->where('email', 'admin@demo.local')->value('password'))))->toBeTrue();

    app()->detectEnvironment(fn (): string => 'local');
    (new AdminUserSeeder())->run();
    expect(adminRoles($this->demo['tenant_id'], 'admin@demo.local'))->toBe(['claims_manager', 'finance_manager', 'tenant_admin']);
});

it('lets the local admin sign in and reach the read-only pages', function (): void {
    $this->withoutVite();
    app()->detectEnvironment(fn (): string => 'local');
    (new RolesSeeder())->run();
    (new AdminUserSeeder())->run();
    app()->detectEnvironment(fn (): string => 'testing'); // back to testing for the HTTP requests (CSRF is skipped only under test)
    $headers = ['X-Tenant' => $this->demo['tenant_id']];

    Pest\Laravel\post('/login', ['email' => 'admin@demo.local', 'password' => 'Seeded-Pass-42'], $headers)->assertRedirect(config('fortify.home'));
    foreach (['/accounting/journals', '/accounting/trial-balance', '/accounting/imports'] as $page) {
        Pest\Laravel\get($page, $headers)->assertOk();
    }
});
