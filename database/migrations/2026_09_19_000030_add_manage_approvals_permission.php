<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix F3 (ASSUMPTION A-54): approval limits are administered with platform.manage_approvals, held by the Tenant Admin template. Existing tenants'
 * tenant_admin role gets it too, per tenant because role_permissions is under row-level security.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'platform.manage_approvals', 'description' => 'Set approval limits: who approves what above which amount']);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'tenant_admin')->value('id');
            if (is_string($roleId)) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => 'platform.manage_approvals']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->where('permission_code', 'platform.manage_approvals')->delete());
        DB::table('permissions')->where('code', 'platform.manage_approvals')->delete();
    }
};
