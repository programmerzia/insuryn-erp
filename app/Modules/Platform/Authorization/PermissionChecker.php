<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use Illuminate\Support\Facades\DB;

/** Design §7: permissions come from roles (role_permissions) assigned to users (user_roles). */
final class PermissionChecker
{
    public function has(string $userId, string $permission): bool
    {
        return DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('rp.permission_code', $permission)
            ->exists();
    }

    /** @throws PermissionDenied */
    public function authorize(string $userId, string $permission): void
    {
        if (! $this->has($userId, $permission)) {
            throw new PermissionDenied($userId, $permission);
        }
    }
}
