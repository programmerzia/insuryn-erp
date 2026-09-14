<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AccountRolesSeeder::class, PermissionsSeeder::class, ProductClassesSeeder::class]);
        $demo = (new DemoTenantSeeder())->run('demo');
        // Slice R8: the demo tenant starts with the default document templates (DemoTenantSeeder itself is unchanged; tests seed templates with a helper).
        \App\Modules\Platform\Tenancy\TenantContext::run($demo['tenant_id'], fn (): int => app(\App\Modules\Platform\Documents\Templates\DocumentTemplates::class)->seedCurrentTenant());
        // Gap audit GA-47 (A-187): branch, product and class required on policy, earning and claim events (tests' DemoTenantSeeder tenants have none unless seeded).
        \App\Modules\Platform\Tenancy\TenantContext::run($demo['tenant_id'], fn (): int => \App\Modules\Accounting\Application\Posting\TenantDimensionRequirements::seedCurrentTenant($demo['tenant_id']));
        $this->call([RolesSeeder::class, AdminUserSeeder::class]);
    }
}
