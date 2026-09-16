<?php

declare(strict_types=1);

namespace App\Http\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Issue Sanctum tokens for ledger integration users (docs/plan/api-accounting-v1.md slice 1). */
final class LedgerTokenController
{
    /** @var list<string> */
    public const ABILITIES = ['integration:events:write', 'integration:events:read'];

    #[LedgerOperation('Issue a Bearer token for an integration user', 'TokenResponse', request: 'TokenRequest', status: 201, ability: null, public: true)]
    public function issue(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string, device_name: string, abilities?: list<string>} $data */
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'], 'abilities.*' => [Rule::in(self::ABILITIES)]]);
        $user = User::query()->where('email', strtolower($data['email']))->where('status', 'active')->where('kind', 'integration')->first();
        if ($user === null || ! Hash::check($data['password'], (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match a ledger integration account.']);
        }
        $abilities = $data['abilities'] ?? self::ABILITIES;

        return response()->json(['data' => ['token' => $user->createToken($data['device_name'], $abilities)->plainTextToken, 'abilities' => $abilities]], 201);
    }

    #[LedgerOperation('Revoke the current token', '', status: 204, ability: null)]
    public function revoke(Request $request): \Illuminate\Http\Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
