<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in user's security settings page: password change and two-factor authentication (Fortify endpoints do the work). */
final class SecurityPageController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('account/Security', [
            'twoFactor' => ['enabled' => $user->two_factor_secret !== null, 'confirmed' => $user->two_factor_confirmed_at !== null],
        ]);
    }
}
