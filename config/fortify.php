<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
 * Local and single-install sign-in. Zitadel OIDC is the target identity provider (LATER, CONTEXT.md); until then Fortify
 * authenticates tenant-scoped users by email and password within the tenant ResolveTenant resolved for the request.
 */
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/accounting/journals',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],
    'views' => true,
    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
