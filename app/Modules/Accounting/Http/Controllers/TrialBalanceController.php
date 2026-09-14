<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Domain\MinorUnits;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Design §9.2 TB report: cumulative debits and credits per account as of a date, from posted journal lines. */
final class TrialBalanceController
{
    public function __invoke(Request $request, LedgerQuery $ledger): Response
    {
        $scope = ReportingScope::fromRequest($request);
        $asOf = $this->asOf($request, $scope->entityId);
        $rows = $ledger->trialBalance($scope->entityId, $scope->bookId, $asOf);
        $debit = array_sum(array_column($rows, 'debit'));
        $credit = array_sum(array_column($rows, 'credit'));

        // UX brief §6.6 period comparison: balances at the end of the previous month.
        $compareAsOf = $asOf->startOfMonth()->subDay();
        $previous = [];
        foreach ($ledger->trialBalance($scope->entityId, $scope->bookId, $compareAsOf) as $row) {
            $previous[$row['account_id']] = MinorUnits::format($row['debit'] - $row['credit'], $scope->currency);
        }

        return Inertia::render('accounting/TrialBalance', [
            'entity' => $scope->entityProps(),
            'asOf' => $asOf->toDateString(),
            'compare' => ['asOf' => $compareAsOf->toDateString(), 'balances' => $previous],
            'rows' => array_map(fn (array $row): array => [
                'accountId' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'],
                'debit' => MinorUnits::format($row['debit'], $scope->currency), 'credit' => MinorUnits::format($row['credit'], $scope->currency),
                'balance' => MinorUnits::format($row['debit'] - $row['credit'], $scope->currency),
            ], $rows),
            'totals' => [
                'debit' => MinorUnits::format($debit, $scope->currency),
                'credit' => MinorUnits::format($credit, $scope->currency),
                'balanced' => $debit === $credit,
            ],
        ]);
    }

    private function asOf(Request $request, string $entityId): CarbonImmutable
    {
        $value = $request->query('as_of');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: app(BusinessClock::class)->today($entityId);
        }

        return app(BusinessClock::class)->today($entityId);
    }
}
