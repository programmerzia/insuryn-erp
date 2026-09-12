<?php

declare(strict_types=1);

use App\Modules\Platform\Tenancy\TenantContext;
use Database\Seeders\AccountRolesSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\PermissionsSeeder;
use PHPUnit\Framework\AssertionFailedError;

/*
 | Tests run against the REAL Postgres (RLS + triggers are the subject under test).
 | phpunit.xml: DB_CONNECTION=pgsql, DB_DATABASE=erp_test, DB_USERNAME=erp_owner (runs migrations).
 | erp_owner must be NOSUPERUSER NOBYPASSRLS: FORCE ROW LEVEL SECURITY then applies to it as table owner
 | (Tests\TestCase refuses to run otherwise). TenantIsolationTest additionally SET ROLE erp_app, the runtime role.
 | Tests\TestCase truncates between tests instead of wrapping each test in a rolled-back transaction.
 */
uses(Tests\TestCase::class)
    ->afterEach(fn () => TenantContext::clear())
    ->in('Feature', 'Architecture');

/** @return array{tenant_id:string, entity_id:string, branch_id:string, book_id:string, accounts:array<string,string>} */
function seedDemoTenant(string $slug = 'demo'): array
{
    (new AccountRolesSeeder())->run();
    (new PermissionsSeeder())->run();
    return (new DemoTenantSeeder())->run($slug);
}

/**
 * @template T
 *
 * @param callable(): T $fn
 * @return T
 */
function asTenant(string $tenantId, callable $fn): mixed
{
    return TenantContext::run($tenantId, $fn);
}

/** The design §7.2 role templates in the tenant (DatabaseSeeder does this for the demo tenant). */
function seedRoleTemplates(string $tenantId): void
{
    asTenant($tenantId, fn () => App\Modules\Platform\Authorization\RoleTemplates::seedCurrentTenant());
}

/**
 * A user of the tenant holding exactly $permissions through one tenant-wide role. Returns the user id.
 *
 * @param list<string> $permissions
 */
function userWithPermissions(string $tenantId, array $permissions, string $scopeType = 'tenant', ?string $scopeId = null): string
{
    return asTenant($tenantId, function () use ($tenantId, $permissions, $scopeType, $scopeId): string {
        $userId = (string) Illuminate\Support\Str::uuid7();
        $roleId = (string) Illuminate\Support\Str::uuid7();
        Illuminate\Support\Facades\DB::table('users')->insert(['id' => $userId, 'tenant_id' => $tenantId, 'email' => "user-{$userId}@example.test", 'name' => 'Test user', 'status' => 'active']);
        Illuminate\Support\Facades\DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'test-'.$roleId, 'name' => 'Test role']);
        foreach ($permissions as $permission) {
            Illuminate\Support\Facades\DB::table('role_permissions')->insert(['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => $permission]);
        }
        Illuminate\Support\Facades\DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId,
            'scope_type' => $scopeType, 'scope_id' => $scopeId ?? $tenantId]);

        return $userId;
    });
}

/**
 * The exception of $type thrown by $operation, for asserting on its details. Fails the test when
 * nothing is thrown; any other exception propagates unchanged.
 *
 * @template TException of Throwable
 *
 * @param class-string<TException> $type
 * @return TException
 */
function thrownBy(callable $operation, string $type): Throwable
{
    try {
        $operation();
    } catch (Throwable $thrown) {
        if ($thrown instanceof $type) {
            return $thrown;
        }
        throw $thrown;
    }

    throw new AssertionFailedError("Expected {$type} to be thrown.");
}
