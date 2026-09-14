<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-41: the claim page prints the claim acknowledgement and discharge vouchers, and `document.generate` was held only by the branch roles.
 * The claims officer and claims manager get it (ASSUMPTION A-184). New tenants get it from RoleTemplates; this adds it to the template roles of existing
 * tenants, as fix G3 did. Roles already edited keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const GRANTS = ['claims_officer' => ['document.generate'], 'claims_manager' => ['document.generate']];

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
