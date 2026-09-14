<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap fixes W7 (GA-24): `receipt.write_off_request` for the branch manager and `receipt.write_off_approve` for the finance manager and CFO (ASSUMPTION A-232).
 * New tenants get them from RoleTemplates; this adds the permissions to the catalogue and to the template roles of existing tenants, as the G3 migration
 * does. Roles already edited keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'receipt.write_off_request' => 'Ask to write off the small premium a cancelled policy still owes',
        'receipt.write_off_approve' => 'Approve the write-off of a cancelled policy\'s small unpaid premium',
    ];

    private const GRANTS = ['branch_manager' => ['receipt.write_off_request'], 'finance_manager' => ['receipt.write_off_approve'], 'cfo' => ['receipt.write_off_approve']];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'description' => $description]);
        }
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
            DB::table('role_permissions')->whereIn('permission_code', array_keys(self::PERMISSIONS))->delete();
        });
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
    }
};
