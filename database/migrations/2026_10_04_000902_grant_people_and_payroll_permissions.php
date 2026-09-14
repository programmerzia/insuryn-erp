<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * People and Payroll MVP (ASSUMPTION A-287): the permission catalogue gains hr.manage_employees, payroll.prepare, payroll.approve, payroll.pay and
 * payroll.manage_rules; existing tenants get the HR Manager template role, payroll.approve on the finance manager and CFO, payroll.pay on the accountant,
 * and the object SoD rules payroll.prepare ✕ payroll.approve and payroll.approve ✕ payroll.pay. Roles already edited keep everything else; rerunning changes nothing.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'hr.manage_employees' => 'Hire employees and record promotions, transfers and pay changes',
        'payroll.prepare' => 'Calculate the monthly payroll preview',
        'payroll.approve' => 'Approve and post a payroll run',
        'payroll.pay' => 'Release the salary bank transfer of a posted payroll run',
        'payroll.manage_rules' => 'Edit salary structures, payroll settings and income tax slabs',
    ];

    private const GRANTS = ['finance_manager' => ['payroll.approve'], 'cfo' => ['payroll.approve'], 'accountant' => ['payroll.pay']];

    private const SOD = [['payroll.prepare', 'payroll.approve', 'SOD-PAYROLL-APPROVE'], ['payroll.approve', 'payroll.pay', 'SOD-PAYROLL-PAY']];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'description' => $description]);
        }
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            if (! DB::table('roles')->exists()) {
                return; // a tenant without role templates gets them, with these, when they are seeded
            }
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                foreach ($roleId === null ? [] : $permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => 'hr_manager', 'name' => 'HR Manager', 'created_at' => now(), 'updated_at' => now()]);
            $hr = (string) DB::table('roles')->where('code', 'hr_manager')->value('id');
            foreach (['hr.manage_employees', 'payroll.prepare', 'payroll.manage_rules', 'party.manage'] as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => $hr, 'permission_code' => $permission]);
            }
            foreach (self::SOD as [$a, $b, $code]) {
                if (! DB::table('sod_rules')->where('permission_a', $a)->where('permission_b', $b)->exists()) {
                    DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'permission_a' => $a, 'permission_b' => $b, 'mode' => 'block', 'applies_to' => 'object']);
                }
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('role_permissions')->whereIn('permission_code', array_keys(self::PERMISSIONS))->delete();
            DB::table('sod_rules')->whereIn('permission_a', ['payroll.prepare', 'payroll.approve'])->delete();
        });
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
    }
};
