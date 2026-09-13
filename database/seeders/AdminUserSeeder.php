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

    private function seedAdmin(string $tenantId, string $email): void
    {
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! is_string($userId)) {
            $userId = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $userId, 'tenant_id' => $tenantId, 'email' => $email, 'name' => 'Tenant Admin',
                'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $roles = ['tenant_admin', ...(app()->environment('local') ? self::LOCAL_ONLY_ROLES : [])];
        foreach ($roles as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id') ?? throw new RuntimeException("Role {$code} is missing; run RolesSeeder first.");
            DB::table('user_roles')->insertOrIgnore(['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
        }
    }
}
