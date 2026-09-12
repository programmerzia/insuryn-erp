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
 * with write permissions. Object-level conflicts are enforced per object by SodGuard instead.
 */
final class RoleAssignmentService
{
    private const SCOPE_TYPES = ['tenant', 'entity', 'branch'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
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
            $currentPermissions = $this->permissions->permissionsOf($userId);
            /** @var list<string> $rolePermissions */
            $rolePermissions = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_code')->all();

            $this->assertAuditorStaysReadOnly($userId, $roleCode, $currentPermissions, $rolePermissions);
            $warnings = $this->userLevelConflicts($userId, $currentPermissions, $rolePermissions);

            DB::table('user_roles')->insert(['tenant_id' => TenantContext::id(), 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
            $this->audit->record('user_role.assigned', AuditSubject::of('user', $userId), null,
                ['role_id' => $roleId, 'role_code' => $roleCode, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                null, 'platform.manage_users', Actor::user($actorUserId));

            return $warnings;
        });
    }

    /**
     * @param list<string> $currentPermissions
     * @param list<string> $rolePermissions
     */
    private function assertAuditorStaysReadOnly(string $userId, string $roleCode, array $currentPermissions, array $rolePermissions): void
    {
        $isAuditor = $roleCode === RoleTemplates::AUDITOR
            || DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->where('r.code', RoleTemplates::AUDITOR)->exists();
        if (! $isAuditor) {
            return;
        }
        $writes = array_values(array_diff([...$currentPermissions, ...$rolePermissions], RoleTemplates::READ_ONLY_PERMISSIONS));
        if ($writes !== []) {
            throw new SodViolation('AUDITOR_WRITE_PERMISSION', $userId, $writes[0], 'audit.view', 'AUDITOR_READ_ONLY',
                "An auditor cannot also hold write permissions ({$writes[0]}).");
        }
    }

    /**
     * @param list<string> $currentPermissions
     * @param list<string> $rolePermissions
     * @return list<SodViolation>
     */
    private function userLevelConflicts(string $userId, array $currentPermissions, array $rolePermissions): array
    {
        $held = array_values(array_unique([...$currentPermissions, ...$rolePermissions]));
        $warnings = [];
        foreach ($rolePermissions as $permission) {
            foreach ($this->sod->rulesInvolving($permission) as $rule) {
                if ($rule->appliesTo !== 'user') {
                    continue;
                }
                $conflictPattern = (string) $rule->conflictFor($permission);
                $conflicting = array_values(array_filter($held, fn (string $p): bool => $p !== $permission && SodRule::matches($conflictPattern, $p)));
                if ($conflicting === []) {
                    continue;
                }
                $violation = new SodViolation('SOD_CONFLICT', $userId, $permission, $conflicting[0], $rule->code,
                    "Segregation of duties ({$rule->code}): a user cannot hold both {$permission} and {$conflicting[0]}.");
                if ($rule->blocks()) {
                    throw $violation;
                }
                $warnings[] = $violation;
            }
        }

        return $warnings;
    }
}
