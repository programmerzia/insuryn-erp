<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication\Actions;

use App\Models\User;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/** Sets a new password once Fortify has validated the reset token (the token is tenant-bound, see User::getEmailForPasswordReset). */
final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'string', Password::defaults(), 'confirmed']])->validate();

        $user->forceFill(['password' => Hash::make($input['password'])])->save();
        // GA-22: an invited user who sets a password has accepted the invitation (the users page stops offering to resend it).
        app(Audit::class)->record('user.password_set', AuditSubject::of('user', $user->id), null, null, null, null, Actor::user($user->id));
    }
}
