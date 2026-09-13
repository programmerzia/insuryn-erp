<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration;

use App\Models\User;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * External portal accounts (Distribution design note §5, slice D9). A portal user is kind `portal`: it never signs in to the staff web app, only
 * obtains API tokens. Its permissions come from a portal role scoped where the caller says (a producer's branch), created on first use. The owning
 * module authorizes the grant with its own permission. The person sets a password from the invitation.
 */
final class PortalAccounts
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @param list<string> $rolePermissions
     *
     * @throws BusinessRuleViolation PORTAL_EMAIL_TAKEN
     */
    public function create(string $name, string $email, string $roleCode, string $roleName, array $rolePermissions, string $scopeType, string $scopeId, string $grantedWith, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, $grantedWith);

        $user = DB::transaction(function () use ($name, $email, $roleCode, $roleName, $rolePermissions, $scopeType, $scopeId, $grantedWith, $actorUserId): User {
            if (User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->exists()) {
                throw new BusinessRuleViolation('PORTAL_EMAIL_TAKEN', "A user with the email {$email} already exists.");
            }
            $user = User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => Str::password(40), 'status' => 'active', 'kind' => 'portal']);
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'code' => $roleCode, 'name' => $roleName, 'created_at' => now(), 'updated_at' => now()]);
            $roleId = (string) DB::table('roles')->where('code', $roleCode)->value('id');
            DB::table('role_permissions')->insertOrIgnore(array_map(fn (string $p): array => ['tenant_id' => TenantContext::id(), 'role_id' => $roleId, 'permission_code' => $p], $rolePermissions));
            DB::table('user_roles')->insert(['tenant_id' => TenantContext::id(), 'user_id' => $user->id, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
            $this->audit->record('user.portal_created', AuditSubject::of('user', $user->id), null, ['email' => $user->email, 'role' => $roleCode, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                null, $grantedWith, Actor::user($actorUserId));

            return $user;
        });
        $user->notify(new UserInvitation(Password::broker()->createToken($user), (string) (DB::table('tenants')->where('id', TenantContext::id())->value('name') ?? config('app.name'))));

        return $user->id;
    }
}
