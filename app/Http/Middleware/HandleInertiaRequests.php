<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/** Inertia root view and the props every page receives (the signed-in user). */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => ['user' => $user instanceof User ? ['id' => $user->id, 'name' => $user->name] : null],
        ];
    }
}
