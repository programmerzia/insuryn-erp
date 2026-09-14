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
    (new Database\Seeders\ProductClassesSeeder())->run(); // Phase 3 R1 global catalogue (truncated between tests like account_roles)
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
        // Slice D2: new business needs a licensed producer; the world's agent holds a licence for both classes for the whole test calendar.
        app(App\Modules\Distribution\Application\Licences\LicenceService::class)->record(new App\Modules\Distribution\Application\Licences\RecordLicence($agent->id, 'IDRA-TEST-001', 'both',
            Carbon\CarbonImmutable::parse('2020-01-01'), Carbon\CarbonImmutable::parse('2030-12-31')), $admin);

        return ['admin' => $admin, 'product_id' => $product->id, 'product_version_id' => $version->id, 'policyholder_id' => $holder->id,
            'agent_id' => $agent->id, 'agent_party_id' => $agentParty->id];
    });
}

/**
 * A rating plan (Phase 3 slice R2) drafted from $definition by one user, then approved and activated by another. Returns the plan id.
 *
 * @param array<string, mixed> $definition the shape of RatingPlanDefinition::toArray()
 */
function activeRatingPlan(string $tenantId, array $definition, bool $supersede = false): string
{
    $maker = userWithPermissions($tenantId, ['rating.manage_plans']);
    $checker = userWithPermissions($tenantId, ['rating.approve_plans']);

    return asTenant($tenantId, function () use ($definition, $maker, $checker, $supersede): string {
        $plans = app(App\Modules\Insurance\Rating\Application\RatingPlanService::class);
        $plan = $plans->createFromDefinition($definition, $maker);
        $plans->approve($plan->id, $checker);

        return $plans->activate($plan->id, $checker, $supersede)->id;
    });
}

/**
 * Phase 3 new-business world (slices R4–R6): seedInsuranceWorld plus the golden motor and fire rating plans (tests/Fixtures/rating 01 and 02) with their
 * duties, and two rated products from 2026-01-01 with the demo risk schemas and coverages — MOTOR-PVT (class motor) and FIRE-SME (class fire).
 * `motor_inputs` / `fire_inputs` are the golden risks (gross 30,918.30 and 57,006.40 on 2026-09-15).
 *
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 * @return array{admin: string, product_id: string, product_version_id: string, policyholder_id: string, agent_id: string, agent_party_id: string,
 *     motor_product_id: string, motor_version_id: string, fire_product_id: string, fire_version_id: string, motor_inputs: array<string, mixed>, fire_inputs: array<string, mixed>,
 *     motor_plan_id: string, fire_plan_id: string}
 */
function ratedProductsWorld(array $ctx): array
{
    $world = seedInsuranceWorld($ctx);
    $fixture = fn (string $name): array => json_decode((string) file_get_contents(__DIR__."/Fixtures/rating/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    $motor = $fixture('01_motor_comprehensive');
    $fire = $fixture('02_fire_per_mille_by_occupancy');
    $motorPlan = activeRatingPlan($ctx['tenant_id'], $motor['plan']);
    $firePlan = activeRatingPlan($ctx['tenant_id'], $fire['plan']);

    return asTenant($ctx['tenant_id'], function () use ($world, $motor, $fire, $motorPlan, $firePlan): array {
        $duties = app(App\Modules\Insurance\Rating\Application\DutyBook::class);
        foreach ([...$motor['duties'], ...array_filter($fire['duties'], fn (array $d): bool => $d['code'] !== 'vat')] as $duty) {
            $duties->record($duty, $world['admin']);
        }
        $catalogue = app(App\Modules\Insurance\Product\Application\ProductCatalogue::class);
        $taxProfile = ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => false, 'refund_tax_on_cancellation' => true];
        $motorProduct = $catalogue->createProduct('MOTOR-PVT', 'Private motor', 'motor', $world['admin']);
        $motorVersion = $catalogue->addVersion($motorProduct->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'posting_rule_set' => 'default',
            'tax_profile' => $taxProfile, 'class_code' => 'motor', 'risk_schema' => Database\Seeders\DemoRatingCatalogue::riskSchema('motor'),
            'coverage_definitions' => Database\Seeders\DemoRatingCatalogue::coverages('motor')], $world['admin']);
        $fireProduct = $catalogue->createProduct('FIRE-SME', 'Fire for small business', 'fire', $world['admin']);
        $fireVersion = $catalogue->addVersion($fireProduct->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'posting_rule_set' => 'default',
            'tax_profile' => $taxProfile, 'class_code' => 'fire', 'risk_schema' => Database\Seeders\DemoRatingCatalogue::riskSchema('fire'),
            'coverage_definitions' => Database\Seeders\DemoRatingCatalogue::coverages('fire')], $world['admin']);

        return [...$world, 'motor_product_id' => $motorProduct->id, 'motor_version_id' => $motorVersion->id, 'fire_product_id' => $fireProduct->id, 'fire_version_id' => $fireVersion->id,
            'motor_inputs' => [...$motor['request']['risk_inputs'], 'chassis_no' => 'CHS-0001-XYZ'], 'fire_inputs' => $fire['request']['risk_inputs'],
            'motor_plan_id' => $motorPlan, 'fire_plan_id' => $firePlan];
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

/** The default document templates (Phase 3 slice R8) in the tenant, as BlankTenantSeeder and the demo seeders give them. */
function seedDocumentTemplates(string $tenantId): void
{
    asTenant($tenantId, fn (): int => app(App\Modules\Platform\Documents\Templates\DocumentTemplates::class)->seedCurrentTenant());
}

/**
 * Replaces headless Chromium with a fake renderer (slice R8): the "PDF" is a small %PDF file holding the SHA-256 of the HTML it was given, and
 * every rendered HTML page is kept, so tests can check what was printed.
 *
 * @return ArrayObject<int, string> the rendered HTML pages, in order
 */
function fakePdfRenderer(): ArrayObject
{
    $pages = new ArrayObject();
    app()->instance(App\Modules\Platform\Documents\Rendering\PdfRenderer::class, new Tests\Support\FakePdfRenderer($pages));

    return $pages;
}
