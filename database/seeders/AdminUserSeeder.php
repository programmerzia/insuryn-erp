<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One admin per tenant for local and single-install use: `admin@<tenant slug>.local` (admin@demo.local for the demo tenant), password from
 * config erp.seed.admin_password (env ERP_ADMIN_PASSWORD). Holds Tenant Admin; in the local environment only, also Finance Manager and Claims
 * Manager so every read-only page can be browsed. That local combination deliberately breaks the §7.3 rule `platform.manage_roles` ✕
 * `accounting.*`, which is why roles are inserted directly instead of through RoleAssignmentService — never outside `local`.
 * Gap fix GA-20: a tenant that already has its own finance manager (the Part A demo's role users) keeps its admin to Tenant Admin only, so the demo
 * shows the segregation rule it sells.
 * Existing admins keep their password. Run RolesSeeder first.
 */
final class AdminUserSeeder extends Seeder
{
    private const LOCAL_ONLY_ROLES = ['finance_manager', 'claims_manager'];

    public function run(): void
    {
        foreach (DB::table('tenants')->orderBy('slug')->get(['id', 'slug']) as $tenant) {
            TenantContext::run((string) $tenant->id, fn () => $this->seedAdmin((string) $tenant->id, "admin@{$tenant->slug}.local"));
        }
    }

    /** Another active user already holds the finance manager role in this tenant. */
    private function hasOwnFinanceManager(string $adminId): bool
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('r.code', 'finance_manager')->where('ur.user_id', '<>', $adminId)->where('u.status', 'active')->exists();
    }

    private function seedAdmin(string $tenantId, string $email): void
    {
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! is_string($userId)) {
            $userId = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $userId, 'tenant_id' => $tenantId, 'email' => $email, 'name' => 'Tenant Admin',
                'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $roles = ['tenant_admin', ...(app()->environment('local') && ! $this->hasOwnFinanceManager($userId) ? self::LOCAL_ONLY_ROLES : [])];
        foreach ($roles as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id') ?? throw new RuntimeException("Role {$code} is missing; run RolesSeeder first.");
            DB::table('user_roles')->insertOrIgnore(['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
        }
    }
}
