<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AccountRolesSeeder::class, PermissionsSeeder::class]);
        $demo = (new DemoTenantSeeder())->run('demo');
        TenantContext::run($demo['tenant_id'], fn () => RoleTemplates::seedCurrentTenant());
    }
}
