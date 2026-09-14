<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap fixes GA-13 and GA-08: `reports.financial` for the accountant (trial balance, account activity, close checklist and reports, read-only;
 * ASSUMPTION A-172) and `accounting.requeue_event` for the finance manager and CFO (retry an accounting event that did not post; ASSUMPTION A-174).
 * New tenants get them from RoleTemplates; this adds them to the template roles of existing tenants, as the G3 migration does. Roles already edited
 * keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const GRANTS = ['accountant' => ['reports.financial'], 'finance_manager' => ['accounting.requeue_event'], 'cfo' => ['accounting.requeue_event']];

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
