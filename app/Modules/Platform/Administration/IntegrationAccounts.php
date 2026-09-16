<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration;

use App\Models\User;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Integration API accounts (docs/plan/api-accounting-v1.md): kind `integration` users obtain Sanctum tokens only;
 * they never sign in to the staff web app (Fortify already limits sign-in to kind `staff`).
 */
final class IntegrationAccounts
{
    public const ROLE = 'ledger_integration';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws BusinessRuleViolation INTEGRATION_EMAIL_TAKEN
     */
    public function create(string $name, string $email, string $password, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');

        return DB::transaction(function () use ($name, $email, $password, $actorUserId): string {
            if (User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->exists()) {
                throw new BusinessRuleViolation('INTEGRATION_EMAIL_TAKEN', "A user with the email {$email} already exists.");
            }
            $user = User::query()->create([
                'name' => $name, 'email' => Str::lower($email), 'password' => Hash::make($password),
                'status' => 'active', 'kind' => 'integration',
            ]);
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'code' => self::ROLE, 'name' => 'Ledger integration', 'created_at' => now(), 'updated_at' => now()]);
            $roleId = (string) DB::table('roles')->where('code', self::ROLE)->value('id');
            DB::table('user_roles')->insert(['tenant_id' => TenantContext::id(), 'user_id' => $user->id, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => TenantContext::id()]);
            $this->audit->record('user.integration_created', \App\Modules\Platform\Audit\AuditSubject::of('user', $user->id), null, ['email' => $user->email], null, 'platform.manage_users', Actor::user($actorUserId));

            return $user->id;
        });
    }
}
