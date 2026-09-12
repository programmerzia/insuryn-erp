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
 * An effective approval policy for $objectType (design §2.1 approval_policies).
 *
 * @param array<string, mixed> $condition
 * @param list<array{permission: string}> $steps
 */
function approvalPolicy(string $tenantId, string $objectType, array $condition, array $steps): string
{
    return asTenant($tenantId, function () use ($tenantId, $objectType, $condition, $steps): string {
        $id = (string) Illuminate\Support\Str::uuid7();
        Illuminate\Support\Facades\DB::table('approval_policies')->insert(['id' => $id, 'tenant_id' => $tenantId, 'object_type' => $objectType,
            'condition' => json_encode($condition, JSON_THROW_ON_ERROR), 'steps' => json_encode($steps, JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01']);

        return $id;
    });
}

/**
 * Insurance test world: a user holding every catalogue permission, VAT 15% (BD), a motor product version,
 * a policyholder and an agent. Returns their ids. Used by Phase 1A/1B tests.
 *
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 * @return array{admin: string, product_id: string, product_version_id: string, policyholder_id: string, agent_id: string, agent_party_id: string}
 */
function seedInsuranceWorld(array $ctx, string $earningMethod = 'monthly', bool $refundTaxOnCancellation = true, ?string $commissionPlanId = null): array
{
    $tenantId = $ctx['tenant_id'];
    $allPermissions = Database\Seeders\PermissionsSeeder::PERMISSIONS;
    $admin = userWithPermissions($tenantId, $allPermissions);

    return asTenant($tenantId, function () use ($ctx, $tenantId, $admin, $earningMethod, $refundTaxOnCancellation, $commissionPlanId): array {
        Illuminate\Support\Facades\DB::table('tax_rates')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $tenantId, 'jurisdiction' => 'BD',
            'tax_type' => 'VAT', 'rate_bp' => 1500, 'inclusive' => true, 'withholding' => false, 'effective_from' => '2026-01-01']);
        $catalogue = app(App\Modules\Insurance\Product\Application\ProductCatalogue::class);
        $product = $catalogue->createProduct('MOTOR', 'Motor Comprehensive', 'motor', $admin);
        $version = $catalogue->addVersion($product->id, [
            'effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => $earningMethod,
            'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => $refundTaxOnCancellation],
            'commission_plan_id' => $commissionPlanId, 'posting_rule_set' => 'default', 'coverages' => [['code' => 'OD', 'name' => 'Own damage']],
        ], $admin);
        $parties = app(App\Modules\Insurance\Party\Application\PartyService::class);
        $holder = $parties->create(App\Modules\Insurance\Party\Domain\Enums\PartyKind::Individual, 'Rahima Akter', null,
            [App\Modules\Insurance\Party\Domain\Enums\PartyRoleType::Customer, App\Modules\Insurance\Party\Domain\Enums\PartyRoleType::Policyholder], $admin);
        $agentParty = $parties->create(App\Modules\Insurance\Party\Domain\Enums\PartyKind::Individual, 'Jamal Agent', null, [App\Modules\Insurance\Party\Domain\Enums\PartyRoleType::Agent], $admin);
        $agent = app(App\Modules\Insurance\Party\Application\AgentService::class)->create($agentParty->id, 'AG-001', $ctx['branch_id'], null, $commissionPlanId, $admin);

        return ['admin' => $admin, 'product_id' => $product->id, 'product_version_id' => $version->id, 'policyholder_id' => $holder->id,
            'agent_id' => $agent->id, 'agent_party_id' => $agentParty->id];
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
