<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\EventTypesQuery;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/event-types */
final class EventTypeController
{
    #[LedgerOperation('Supported event types from posting rules', 'EventTypes')]
    public function index(EventTypesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->list()]);
    }
}
