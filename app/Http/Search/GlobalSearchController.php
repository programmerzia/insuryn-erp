<?php

declare(strict_types=1);

namespace App\Http\Search;

use App\Http\Pages\PageSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /search?q=… for the command palette (UX brief §4). */
final class GlobalSearchController
{
    public function __invoke(Request $request, GlobalSearchQuery $search): JsonResponse
    {
        return response()->json(['results' => $search->search(PageSupport::actor($request), (string) $request->query('q', ''))]);
    }
}
