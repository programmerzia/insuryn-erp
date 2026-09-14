<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-12: the claims desk had no report at all (every report needed reports.financial). New permission `reports.claims` opens the outstanding claims
 * register, claims paid and the loss ratio, read-only (ASSUMPTION A-175); the claims officer and claims manager templates hold it. This adds it to the
 * catalogue and to existing tenants' template roles; rerunning changes nothing.
 */
return new class extends Migration
{
    private const GRANTS = ['claims_officer' => ['reports.claims'], 'claims_manager' => ['reports.claims']];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'reports.claims', 'description' => 'Read the claims reports: outstanding claims, claims paid and loss ratio']);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach ($permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->where('permission_code', 'reports.claims')->delete());
        DB::table('permissions')->where('code', 'reports.claims')->delete();
    }
};
