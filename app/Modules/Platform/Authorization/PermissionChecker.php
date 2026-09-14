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
     * Read access to a screen area: the user holds at least one of $permissions.
     *
     * @param list<string> $permissions
     *
     * @throws PermissionDenied naming the permissions, any of which would do
     */
    public function authorizeAny(string $userId, array $permissions, ?AuthorizationScope $scope = null): void
    {
        foreach ($permissions as $permission) {
            if ($this->has($userId, $permission, $scope)) {
                return;
            }
        }

        throw new PermissionDenied($userId, implode('|', $permissions));
    }

    /**
     * G2: where the user holds any of $permissions — tenant-wide, in entities, or in branches (design §7.2 user_roles.scope).
     *
     * @param list<string> $permissions
     */
    public function reach(string $userId, array $permissions): AreaReach
    {
        $grants = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->whereIn('rp.permission_code', $permissions)->distinct()->get(['ur.scope_type', 'ur.scope_id']);
        $ids = fn (string $type): array => array_values(array_unique($grants->where('scope_type', $type)->pluck('scope_id')->map(fn (mixed $id): string => (string) $id)->all()));

        return new AreaReach($grants->contains('scope_type', 'tenant'), $ids('entity'), $ids('branch'));
    }

    /**
     * Opens a screen area to a holder of any of $permissions in any scope (a branch officer's role is usually scoped to the branch) and returns where they
     * hold it, to limit its lists. A record page then checks the record's own scope with authorizeAny.
     *
     * @param list<string> $permissions
     *
     * @throws PermissionDenied naming the permissions, any of which would do
     */
    public function authorizeArea(string $userId, array $permissions): AreaReach
    {
        $reach = $this->reach($userId, $permissions);
        if ($reach->isEmpty()) {
            throw new PermissionDenied($userId, implode('|', $permissions));
        }

        return $reach;
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
