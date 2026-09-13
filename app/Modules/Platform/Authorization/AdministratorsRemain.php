<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * ASSUMPTION (conservative, phase 2.0): a tenant must never lock itself out of administration. After any change to users, roles or their
 * permissions, at least one active user still holds platform.manage_users and one holds platform.manage_roles. Callers run this inside the
 * transaction that made the change, so a refusal rolls it back.
 */
final class AdministratorsRemain
{
    public const ADMINISTRATION = ['platform.manage_users' => 'users', 'platform.manage_roles' => 'roles'];

    /** @return string|null "users" or "roles" when nobody active is left who can manage them */
    public function missing(): ?string
    {
        foreach (self::ADMINISTRATION as $permission => $what) {
            $held = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')
                ->where('rp.permission_code', $permission)->where('u.status', 'active')->exists();
            if (! $held) {
                return $what;
            }
        }

        return null;
    }

    /** @throws BusinessRuleViolation LAST_ADMINISTRATOR naming the user whose change would leave nobody */
    public function assertAfterChangeTo(string $userName): void
    {
        $missing = $this->missing();
        if ($missing !== null) {
            throw new BusinessRuleViolation('LAST_ADMINISTRATOR', "{$userName} is the last active user who can manage {$missing}. Give someone else that permission first.");
        }
    }
}
