<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration;

use App\Models\User;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AdministratorsRemain;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The Tenant Admin's user lifecycle (phase 2.0): invite (the user sets their own password from an emailed link), resend the invitation,
 * deactivate (signs them out everywhere) and reactivate. Role changes go through RoleAssignmentService.
 * ASSUMPTION (A-12): an invited user is active with a random password nobody knows and sets their own from a standard reset link.
 * ASSUMPTION (A-11): nobody deactivates their own account, and the tenant keeps its administrators (AdministratorsRemain).
 */
final class UserAdministration
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AdministratorsRemain $administrators,
        private readonly Audit $audit,
    ) {}

    /** @throws \App\Modules\Platform\Authorization\PermissionDenied */
    public function invite(string $name, string $email, string $actorUserId): User
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');

        $user = DB::transaction(function () use ($name, $email, $actorUserId): User {
            // Nobody knows this password; the invitation link replaces it.
            $user = User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => Str::password(40), 'status' => 'active']);
            $this->audit->record('user.invited', AuditSubject::of('user', $user->id), null, ['name' => $user->name, 'email' => $user->email],
                null, 'platform.manage_users', Actor::user($actorUserId));

            return $user;
        });
        $this->sendInvitation($user);

        return $user;
    }

    /** @throws \App\Modules\Platform\Authorization\PermissionDenied */
    public function resendInvitation(User $user, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');
        $this->sendInvitation($user);
        $this->audit->record('user.invitation_sent', AuditSubject::of('user', $user->id), null, null, null, 'platform.manage_users', Actor::user($actorUserId));
    }

    /**
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     * @throws BusinessRuleViolation CANNOT_DEACTIVATE_SELF, LAST_ADMINISTRATOR
     */
    public function deactivate(User $user, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');
        if ($user->id === $actorUserId) {
            throw new BusinessRuleViolation('CANNOT_DEACTIVATE_SELF', 'You cannot deactivate your own account. Ask another administrator.');
        }

        DB::transaction(function () use ($user, $actorUserId): void {
            $this->changeStatus($user, 'inactive', 'user.deactivated', $actorUserId);
            $this->administrators->assertAfterChangeTo($user->name);
        });
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /** @throws \App\Modules\Platform\Authorization\PermissionDenied */
    public function reactivate(User $user, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'platform.manage_users');
        DB::transaction(fn () => $this->changeStatus($user, 'active', 'user.reactivated', $actorUserId));
    }

    private function changeStatus(User $user, string $status, string $action, string $actorUserId): void
    {
        $before = $user->status;
        $user->forceFill(['status' => $status])->save();
        $this->audit->record($action, AuditSubject::of('user', $user->id), ['status' => $before], ['status' => $status], null, 'platform.manage_users', Actor::user($actorUserId));
    }

    private function sendInvitation(User $user): void
    {
        $organisation = (string) (DB::table('tenants')->where('id', TenantContext::id())->value('name') ?? config('app.name'));
        $user->notify(new UserInvitation(Password::broker()->createToken($user), $organisation));
    }
}
