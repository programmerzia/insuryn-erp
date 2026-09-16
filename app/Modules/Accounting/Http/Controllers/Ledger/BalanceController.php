<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\LedgerBalanceQuery;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/balances and /api/v1/trial-balance */
final class BalanceController
{
    #[LedgerOperation('Account balance as of a date', 'AccountBalance', query: ['as_of' => ['string', 'YYYY-MM-DD'], 'account' => ['string', 'Account code (required)'], 'dimension' => ['string', 'Optional dimension name'], 'value' => ['string', 'Dimension value when dimension is set']])]
    public function show(Request $request, LedgerBalanceQuery $query): JsonResponse
    {
        $account = $request->query('account');
        if (! is_string($account) || $account === '') {
            return response()->json(['message' => 'The account query parameter is required.', 'errors' => ['account' => ['The account field is required.']]], 422);
        }
        $asOf = $this->asOf($request);
        $dimension = $request->query('dimension');
        $value = $request->query('value');

        return response()->json(['data' => $query->accountBalance(
            $account,
            $asOf,
            is_string($dimension) && $dimension !== '' ? $dimension : null,
            is_string($value) && $value !== '' ? $value : null,
        )]);
    }

    #[LedgerOperation('Trial balance as of a date', 'TrialBalance', query: ['as_of' => ['string', 'YYYY-MM-DD']])]
    public function trialBalance(Request $request, LedgerBalanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->trialBalance($this->asOf($request))]);
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $value = $request->query('as_of');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: CarbonImmutable::today();
        }
        $entityId = (string) \Illuminate\Support\Facades\DB::table('legal_entities')->orderBy('code')->value('id');

        return app(BusinessClock::class)->today($entityId);
    }
}
