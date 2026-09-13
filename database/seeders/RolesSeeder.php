<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Design §7.2 role templates (Branch Officer … Auditor, Tenant Admin) with their permissions, in every tenant. Safe to rerun. */
final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DB::table('tenants')->orderBy('slug')->pluck('id') as $tenantId) {
            TenantContext::run((string) $tenantId, static fn () => RoleTemplates::seedCurrentTenant());
        }
    }
}
