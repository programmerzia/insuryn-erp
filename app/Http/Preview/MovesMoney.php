<?php

declare(strict_types=1);

namespace App\Http\Preview;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route marker (alias `moves-money`): the action posts to the ledger, so its form may ask for a journal preview (PreviewJournal). */
final class MovesMoney
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
