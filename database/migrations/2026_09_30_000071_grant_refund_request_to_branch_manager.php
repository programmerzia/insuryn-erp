<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-01: no role template held `receipt.refund_request`, so no shipped role could refund the money of a cancelled policy. The branch manager
 * template now holds it (ASSUMPTION A-171); this adds it to the branch_manager role of existing tenants, as the G3 migration does. Roles already edited
 * keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const GRANTS = ['branch_manager' => ['receipt.refund_request']];

    public function up(): void
    {
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
        RowLevelSecurity::forEachTenant(function (): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                DB::table('role_permissions')->whereIn('permission_code', $permissions)
                    ->whereIn('role_id', DB::table('roles')->where('code', $roleCode)->select('id'))->delete();
            }
        });
    }
};
