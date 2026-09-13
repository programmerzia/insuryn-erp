<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/** The signed-in user changes their own password; the current password is required. */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], ['current_password.current_password' => 'The current password is not correct.'])->validateWithBag('updatePassword');

        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
