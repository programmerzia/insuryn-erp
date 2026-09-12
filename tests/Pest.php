<?php

declare(strict_types=1);

use App\Modules\Platform\Tenancy\TenantContext;
use Database\Seeders\AccountRolesSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 | Tests run against the REAL Postgres from docker-compose (RLS + triggers are the subject under test).
 | phpunit.xml: set DB_CONNECTION=pgsql, DB_DATABASE=erp_test, DB_USERNAME=erp_owner for migrations.
 | NOTE: as erp_owner RLS is not FORCED for the owner… so tenancy tests explicitly SET ROLE erp_app. See TenantIsolationTest.
 */
uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature', 'Architecture');

/** @return array{tenant_id:string, entity_id:string, branch_id:string, book_id:string, accounts:array<string,string>} */
function seedDemoTenant(string $slug = 'demo'): array
{
    (new AccountRolesSeeder())->run();
    (new PermissionsSeeder())->run();
    return (new DemoTenantSeeder())->run($slug);
}

/** @template T @param callable():T $fn @return T */
function asTenant(string $tenantId, callable $fn): mixed
{
    return TenantContext::run($tenantId, $fn);
}
