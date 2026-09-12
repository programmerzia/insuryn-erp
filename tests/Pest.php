<?php

declare(strict_types=1);

use App\Modules\Platform\Tenancy\TenantContext;
use Database\Seeders\AccountRolesSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\PermissionsSeeder;

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
