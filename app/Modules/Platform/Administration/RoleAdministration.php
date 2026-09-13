<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AdministratorsRemain;
use App\Modules\Platform\Authorization\HeldPermissionsPolicy;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles are "seeded templates per tenant, editable" (design §7.2). A role's permissions reach every holder, so a change is checked against
 * each holder as if they were being given the added permissions (HeldPermissionsPolicy), and never leaves the tenant without administrators.
 */
final class RoleAdministration
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly HeldPermissionsPolicy $policy,
        private readonly AdministratorsRemain $administrators,
        private readonly Audit $audit,
    ) {}

    /**
     * @return string the new role's id
     *
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     */
    public function create(string $name, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_roles');

        return DB::transaction(function () use ($name, $actorUserId): string {
            $id = (string) Str::uuid7();
            DB::table('roles')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'code' => self::codeFor($name), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('role.created', AuditSubject::of('role', $id), null, ['name' => $name], null, 'platform.manage_roles', Actor::user($actorUserId));

            return $id;
        });
    }

    public static function codeFor(string $name): string
    {
        return Str::slug($name, '_');
    }

    /**
     * @param list<string> $permissions the role's complete new permission set
     * @return array{added: list<string>, removed: list<string>}
     *
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     * @throws \App\Modules\Platform\Authorization\SodViolation for the first holder the change would put in conflict
     * @throws BusinessRuleViolation LAST_ADMINISTRATOR
     */
    public function updatePermissions(string $roleId, array $permissions, string $actorUserId): array
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_roles');

        return DB::transaction(function () use ($roleId, $permissions, $actorUserId): array {
            $roleCode = (string) DB::table('roles')->where('id', $roleId)->lockForUpdate()->value('code');
            /** @var list<string> $current */
            $current = DB::table('role_permissions')->where('role_id', $roleId)->orderBy('permission_code')->pluck('permission_code')->all();
            $wanted = array_values(array_unique($permissions));
            sort($wanted);
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));
            if ($added === [] && $removed === []) {
                return ['added' => [], 'removed' => []];
            }

            foreach ($this->holders($roleId) as $holderId) {
                $this->policy->check($holderId, $roleCode, [...$this->permissionsFromOtherRoles($holderId, $roleId), ...$wanted], $added);
            }

            DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_code', $removed)->delete();
            DB::table('role_permissions')->insert(array_map(fn (string $p): array => ['tenant_id' => TenantContext::id(), 'role_id' => $roleId, 'permission_code' => $p], $added));

            $missing = $this->administrators->missing();
            if ($missing !== null) {
                throw new BusinessRuleViolation('LAST_ADMINISTRATOR', "Nobody active would be left who can manage {$missing}. Give that permission to someone else first.");
            }
            $this->audit->record('role.permissions_changed', AuditSubject::of('role', $roleId), null, ['added' => $added, 'removed' => $removed],
                null, 'platform.manage_roles', Actor::user($actorUserId));

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     * @throws BusinessRuleViolation ROLE_IN_USE
     */
    public function delete(string $roleId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_roles');

        DB::transaction(function () use ($roleId, $actorUserId): void {
            $role = DB::table('roles')->where('id', $roleId)->lockForUpdate()->first(['code', 'name']);
            if ($role === null) {
                return;
            }
            $holders = count($this->holders($roleId));
            if ($holders > 0) {
                throw new BusinessRuleViolation('ROLE_IN_USE', "{$role->name} is held by {$holders} ".Str::plural('user', $holders).'. Remove it from them first.');
            }
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
            $this->audit->record('role.deleted', AuditSubject::of('role', $roleId), ['code' => (string) $role->code, 'name' => (string) $role->name], null,
                null, 'platform.manage_roles', Actor::user($actorUserId));
        });
    }

    /** @return list<string> */
    private function holders(string $roleId): array
    {
        /** @var list<string> */
        return DB::table('user_roles')->where('role_id', $roleId)->distinct()->orderBy('user_id')->pluck('user_id')->map(fn ($id): string => (string) $id)->all();
    }

    /** @return list<string> */
    private function permissionsFromOtherRoles(string $userId, string $roleId): array
    {
        /** @var list<string> */
        return DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->where('ur.role_id', '<>', $roleId)->distinct()->orderBy('rp.permission_code')->pluck('rp.permission_code')->all();
    }
}
