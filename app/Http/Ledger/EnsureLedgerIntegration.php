<?php

declare(strict_types=1);

namespace App\Http\Ledger;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ledger API requests: an integration user (kind `integration`) only; portal and staff session users are refused. */
final class EnsureLedgerIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->getAttribute('kind') !== 'integration') {
            return response()->json(['message' => 'This token is not a ledger integration token.', 'reason' => 'NOT_AN_INTEGRATION_ACCOUNT'], 403);
        }

        return $next($request);
    }
}
