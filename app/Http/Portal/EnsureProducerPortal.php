<?php

declare(strict_types=1);

namespace App\Http\Portal;

use App\Modules\Distribution\Application\Portal\ProducerPortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Producer portal requests (design note §5): a portal user linked to an active producer; the producer is put on the request for the endpoints. */
final class EnsureProducerPortal
{
    public function __construct(private readonly ProducerPortalAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $producer = $user === null || $user->getAttribute('kind') !== 'portal' ? null : $this->access->producerFor((string) $user->getAuthIdentifier());
        if ($producer === null) {
            return response()->json(['message' => 'This account is not a producer portal account.', 'reason' => 'NOT_A_PORTAL_ACCOUNT'], 403);
        }
        if (! $producer->isActive()) {
            return response()->json(['message' => "{$producer->code} is {$producer->status}; the portal is closed until it is active again.", 'reason' => 'PRODUCER_NOT_ACTIVE'], 403);
        }
        $request->attributes->set('producer', $producer);

        return $next($request);
    }
}
