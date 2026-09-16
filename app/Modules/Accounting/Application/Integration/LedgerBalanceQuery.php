<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\LedgerQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Balances and trial balance for GET /api/v1/balances and /api/v1/trial-balance. */
final class LedgerBalanceQuery
{
    public function __construct(private readonly LedgerQuery $ledger) {}

    /** @return array<string, mixed> */
    public function accountBalance(string $accountCode, CarbonImmutable $asOf, ?string $dimension = null, ?string $dimensionValue = null): array
    {
        $scope = LedgerScope::resolve();
        $account = DB::table('accounts')->where('entity_id', $scope->entityId)->where('code', $accountCode)->first(['id', 'code', 'name']);
        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }
        $accountId = (string) $account->id;
        if ($dimension !== null && $dimensionValue !== null) {
            $split = $this->ledger->normalBalanceByDimension([$accountId], $scope->bookId, $asOf, $dimension);
            $balance = $split['by_dimension'][$dimensionValue] ?? 0;

            return [
                'account_code' => (string) $account->code,
                'account_name' => (string) $account->name,
                'as_of' => $asOf->toDateString(),
                'currency' => $scope->currency,
                'balance_minor' => $balance,
                'dimension' => $dimension,
                'dimension_value' => $dimensionValue,
            ];
        }

        return [
            'account_code' => (string) $account->code,
            'account_name' => (string) $account->name,
            'as_of' => $asOf->toDateString(),
            'currency' => $scope->currency,
            'balance_minor' => $this->ledger->balance($accountId, $scope->bookId, $asOf),
        ];
    }

    /** @return array<string, mixed> */
    public function trialBalance(CarbonImmutable $asOf): array
    {
        $scope = LedgerScope::resolve();
        $rows = $this->ledger->trialBalance($scope->entityId, $scope->bookId, $asOf);
        $debit = array_sum(array_column($rows, 'debit'));
        $credit = array_sum(array_column($rows, 'credit'));

        return [
            'entity_code' => $scope->entityCode,
            'as_of' => $asOf->toDateString(),
            'currency' => $scope->currency,
            'rows' => array_map(fn (array $row): array => [
                'account_code' => $row['code'],
                'account_name' => $row['name'],
                'type' => $row['type'],
                'debit_minor' => $row['debit'],
                'credit_minor' => $row['credit'],
                'balance_minor' => $row['debit'] - $row['credit'],
            ], $rows),
            'totals' => ['debit_minor' => $debit, 'credit_minor' => $credit, 'balanced' => $debit === $credit],
        ];
    }
}
