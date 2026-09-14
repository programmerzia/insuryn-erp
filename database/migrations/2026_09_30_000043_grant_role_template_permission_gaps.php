<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix G3 (flow audit): role template gaps. `reports.regulatory` for the finance manager and CFO (they file the regulatory returns, Part A step 14), and
 * `policy.endorse` for the branch manager (ASSUMPTION A-137) and `commission.pay` for the accountant (ASSUMPTION A-138): no §7.2 template held them. New tenants get them from RoleTemplates; this adds them to the
 * template roles of existing tenants, as the slice migrations do. Roles already edited keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const GRANTS = ['finance_manager' => ['reports.regulatory'], 'cfo' => ['reports.regulatory'], 'branch_manager' => ['policy.endorse'], 'accountant' => ['commission.pay']];

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
