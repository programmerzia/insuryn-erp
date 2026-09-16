<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\LedgerReportQuery;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /api/v1/reports/profit-and-loss and balance-sheet */
final class ReportController
{
    #[LedgerOperation('Profit and loss for a date range', 'ProfitAndLoss', query: ['from' => ['string', 'YYYY-MM-DD'], 'to' => ['string', 'YYYY-MM-DD']])]
    public function profitAndLoss(Request $request, LedgerReportQuery $query): JsonResponse
    {
        /** @var array{from: string, to: string} $data */
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $query->profitAndLoss(
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        )]);
    }

    #[LedgerOperation('Balance sheet as of a date', 'BalanceSheet', query: ['as_of' => ['string', 'YYYY-MM-DD']])]
    public function balanceSheet(Request $request, LedgerReportQuery $query): JsonResponse
    {
        $asOf = $this->asOf($request);

        return response()->json(['data' => $query->balanceSheet($asOf)]);
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $value = $request->query('as_of');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: CarbonImmutable::today();
        }
        $entityId = (string) DB::table('legal_entities')->orderBy('code')->value('id');

        return app(BusinessClock::class)->today($entityId);
    }
}
