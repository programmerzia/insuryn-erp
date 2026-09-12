<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Design §7: permissions come from roles (role_permissions) assigned to users (user_roles) within a scope. */
final class PermissionChecker
{
    /** @param AuthorizationScope|null $scope null = the action is not tied to an entity or branch (tenant-level) */
    public function has(string $userId, string $permission, ?AuthorizationScope $scope = null): bool
    {
        $scope ??= AuthorizationScope::tenant();

        return DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('rp.permission_code', $permission)
            ->where(fn (Builder $grants) => $this->grantsCovering($grants, $scope))
            ->exists();
    }

    /** @throws PermissionDenied */
    public function authorize(string $userId, string $permission, ?AuthorizationScope $scope = null): void
    {
        if (! $this->has($userId, $permission, $scope)) {
            throw new PermissionDenied($userId, $permission);
        }
    }

    /**
     * Every permission the user holds through any role, regardless of scope (role-assignment SoD checks).
     *
     * @return list<string>
     */
    public function permissionsOf(string $userId): array
    {
        /** @var list<string> */
        return DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->distinct()->orderBy('rp.permission_code')->pluck('rp.permission_code')->all();
    }

    private function grantsCovering(Builder $grants, AuthorizationScope $scope): void
    {
        $grants->where('ur.scope_type', 'tenant');
        if ($scope->entityId !== null) {
            $grants->orWhere(fn (Builder $q) => $q->where('ur.scope_type', 'entity')->where('ur.scope_id', $scope->entityId));
        }
        if ($scope->branchId !== null) {
            $grants->orWhere(fn (Builder $q) => $q->where('ur.scope_type', 'branch')->where('ur.scope_id', $scope->branchId));
        }
    }
}
