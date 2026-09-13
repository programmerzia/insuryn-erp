<?php

declare(strict_types=1);

namespace App\Http\Portal;

use App\Http\Portal\OpenApi\PortalOperation;
use App\Models\User;
use App\Modules\Distribution\Application\Portal\ProducerPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Producer portal tokens (slice D9): portal users sign in with email and password inside their tenant and get a Sanctum token; sign-out revokes it. */
final class PortalTokenController
{
    public const ABILITIES = ['portal:read', 'portal:collect'];

    #[PortalOperation(summary: 'Sign in: get a token for this device', response: 'TokenResponse', request: 'TokenRequest', status: 201, ability: null, errors: [422], public: true)]
    public function issue(Request $request, ProducerPortalAccess $access): JsonResponse
    {
        /** @var array{email: string, password: string, device_name: string, abilities?: list<string>} $data */
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'], 'abilities.*' => [Rule::in(self::ABILITIES)]]);
        $user = User::query()->where('email', strtolower($data['email']))->where('status', 'active')->where('kind', 'portal')->first();
        if ($user === null || ! Hash::check($data['password'], (string) $user->getAuthPassword()) || $access->producerFor($user->id) === null) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match a producer portal account.']);
        }
        $abilities = $data['abilities'] ?? self::ABILITIES;

        return response()->json(['data' => ['token' => $user->createToken($data['device_name'], $abilities)->plainTextToken, 'abilities' => $abilities]], 201);
    }

    #[PortalOperation(summary: 'Sign out: revoke the token used for this request', response: '', status: 204, ability: null)]
    public function revoke(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
