<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Design §7.3 enforcement point (1): assigning a role must not give a user both sides of a user-level
 * conflict (block-mode rules refuse, warn-mode rules are audited), and the auditor role never combines
 * with write permissions (HeldPermissionsPolicy). Object-level conflicts are enforced per object by SodGuard instead.
 */
final class RoleAssignmentService
{
    private const SCOPE_TYPES = ['tenant', 'entity', 'branch'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly HeldPermissionsPolicy $policy,
        private readonly AdministratorsRemain $administrators,
        private readonly Audit $audit,
    ) {}

    /**
     * @return list<SodViolation> warnings from warn-mode rules
     *
     * @throws PermissionDenied without platform.manage_users
     * @throws SodViolation
     */
    public function assign(string $userId, string $roleId, string $scopeType, string $scopeId, string $actorUserId): array
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown role scope type {$scopeType}.");
        }

        return DB::transaction(function () use ($userId, $roleId, $scopeType, $scopeId, $actorUserId): array {
            $roleCode = (string) DB::table('roles')->where('id', $roleId)->value('code');
            /** @var list<string> $rolePermissions */
            $rolePermissions = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_code')->all();

            $warnings = $this->policy->check($userId, $roleCode, $this->permissions->permissionsOf($userId), $rolePermissions);

            DB::table('user_roles')->insert(['tenant_id' => TenantContext::id(), 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
            $this->audit->record('user_role.assigned', AuditSubject::of('user', $userId), null,
                ['role_id' => $roleId, 'role_code' => $roleCode, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                null, 'platform.manage_users', Actor::user($actorUserId));

            return $warnings;
        });
    }

    /**
     * Takes a role away within one scope. Refused when it would leave nobody active who can manage users or roles.
     *
     * @throws PermissionDenied without platform.manage_users
     * @throws \App\Modules\Platform\Exceptions\BusinessRuleViolation LAST_ADMINISTRATOR
     */
    public function revoke(string $userId, string $roleId, string $scopeType, string $scopeId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');

        DB::transaction(function () use ($userId, $roleId, $scopeType, $scopeId, $actorUserId): void {
            $removed = DB::table('user_roles')->where(['user_id' => $userId, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId])->delete();
            if ($removed === 0) {
                return;
            }
            $this->administrators->assertAfterChangeTo((string) DB::table('users')->where('id', $userId)->value('name'));
            $this->audit->record('user_role.revoked', AuditSubject::of('user', $userId),
                ['role_id' => $roleId, 'role_code' => (string) DB::table('roles')->where('id', $roleId)->value('code'), 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                null, null, 'platform.manage_users', Actor::user($actorUserId));
        });
    }
}
