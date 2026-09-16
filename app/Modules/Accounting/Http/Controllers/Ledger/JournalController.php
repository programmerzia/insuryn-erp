<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\LedgerJournalQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** GET /api/v1/journals/{journal} */
final class JournalController
{
    #[LedgerOperation('Journal with lines and totals', 'JournalDetail', status: 200)]
    public function show(string $journal, LedgerJournalQuery $query): JsonResponse
    {
        $row = $query->find($journal);
        if ($row === null) {
            throw new NotFoundHttpException('Journal not found.');
        }

        return response()->json(['data' => $row]);
    }
}
