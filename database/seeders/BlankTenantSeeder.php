<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Documents\Templates\DocumentTemplates;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A tenant as it is on its first day (session S1): the tenant row, the §7.2 role templates and the §7.3 segregation-of-duties rules — no
 * company, periods, chart of accounts or products. The setup wizard does the rest. `php artisan erp:tenant` runs this and AdminUserSeeder.
 * Rerunning with an existing slug returns that tenant unchanged.
 */
final class BlankTenantSeeder extends Seeder
{
    public function run(string $slug = 'acme', string $name = 'Acme Insurance'): string
    {
        (new AccountRolesSeeder())->run();
        (new PermissionsSeeder())->run();
        (new ProductClassesSeeder())->run();
        $existing = DB::table('tenants')->where('slug', $slug)->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $tenantId = (string) Str::uuid7();
        DB::table('tenants')->insert(['id' => $tenantId, 'name' => $name, 'slug' => $slug, 'timezone' => 'Asia/Dhaka', 'fiscal_year_start_month' => 7,
            'base_currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        TenantContext::run($tenantId, function () use ($tenantId): void {
            RoleTemplates::seedCurrentTenant();
            app(DocumentTemplates::class)->seedCurrentTenant(); // slice R8: default document templates, English and Bangla
            \App\Modules\Accounting\Application\Posting\TenantDimensionRequirements::seedCurrentTenant($tenantId); // gap audit GA-47 (A-187)
            foreach (PermissionsSeeder::SOD as $i => [$a, $b, $appliesTo]) {
                DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => 'SOD'.($i + 1), 'permission_a' => $a, 'permission_b' => $b, 'mode' => 'block', 'applies_to' => $appliesTo]);
            }
        });

        return $tenantId;
    }
}
