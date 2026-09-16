<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use Carbon\CarbonImmutable;

/** P&L and balance sheet for GET /api/v1/reports/* */
final class LedgerReportQuery
{
    public function __construct(private readonly FinancialStatementsQuery $statements) {}

    /** @return array<string, mixed> */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $scope = LedgerScope::resolve();

        return $this->statements->profitAndLoss($scope->entityId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function balanceSheet(CarbonImmutable $asOf): array
    {
        $scope = LedgerScope::resolve();

        return $this->statements->balanceSheet($scope->entityId, $asOf);
    }
}
