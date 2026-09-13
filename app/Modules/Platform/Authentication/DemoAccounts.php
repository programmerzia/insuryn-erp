<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Local convenience for the sign-in page: the resolved tenant's seeded accounts (AdminUserSeeder, DemoBusinessSeeder — `*.local` emails)
 * with the seed password, so a developer fills the form in one click. Returns null outside the local environment so the prop never ships.
 */
final class DemoAccounts
{
    /**
     * @return list<array{email: string, name: string, roles: list<string>, password: string}>|null
     */
    public static function forSignIn(): ?array
    {
        if (! app()->environment('local')) {
            return null;
        }
        if (! TenantContext::has()) {
            return [];
        }

        $rows = DB::table('users')->leftJoin('user_roles', 'user_roles.user_id', '=', 'users.id')->leftJoin('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.status', 'active')->where('users.email', 'like', '%.local')
            ->orderBy('users.email')->orderBy('roles.name')->get(['users.email', 'users.name', 'roles.name as role']);
        $password = (string) config('erp.seed.admin_password');

        $accounts = [];
        foreach ($rows as $row) {
            $email = (string) $row->email;
            $accounts[$email] ??= ['email' => $email, 'name' => (string) $row->name, 'roles' => [], 'password' => $password];
            if ($row->role !== null && ! in_array((string) $row->role, $accounts[$email]['roles'], true)) {
                $accounts[$email]['roles'][] = (string) $row->role;
            }
        }

        return array_values($accounts);
    }
}
