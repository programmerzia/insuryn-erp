<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reports;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only financial statements from posted journal lines of the primary book (design §6.1: the GL is the source of truth). Amounts are
 * on each account's normal side. Every account row links to its activity, and every activity line to its journal. Income and expense are
 * cumulative in the balance sheet's current earnings (there is no year-end close into retained earnings yet).
 */
final class FinancialStatementsQuery
{
    private const LEDGER_STATUSES = ['posted', 'reversed'];

    /**
     * @return array{entity_id: string, from: string, to: string, income: list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>,
     *     expense: list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>, total_income_minor: int, total_expense_minor: int, net_profit_minor: int}
     */
    public function profitAndLoss(string $entityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->accountBalances($entityId, ['income', 'expense'], $from, $to);
        $query = "&from={$from->toDateString()}&to={$to->toDateString()}";
        $income = $this->section($rows, 'income', $entityId, $query);
        $expense = $this->section($rows, 'expense', $entityId, $query);
        $totalIncome = array_sum(array_column($income, 'amount_minor'));
        $totalExpense = array_sum(array_column($expense, 'amount_minor'));

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'income' => $income, 'expense' => $expense,
            'total_income_minor' => $totalIncome, 'total_expense_minor' => $totalExpense, 'net_profit_minor' => $totalIncome - $totalExpense];
    }

    /**
     * @return array{entity_id: string, as_of: string, assets: list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>,
     *     liabilities: list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>,
     *     equity: list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>,
     *     current_earnings_minor: int, total_assets_minor: int, total_liabilities_minor: int, total_equity_minor: int}
     */
    public function balanceSheet(string $entityId, CarbonImmutable $asOf): array
    {
        $rows = $this->accountBalances($entityId, ['asset', 'liability', 'equity', 'income', 'expense'], null, $asOf);
        $query = "&to={$asOf->toDateString()}";
        $assets = $this->section($rows, 'asset', $entityId, $query);
        $liabilities = $this->section($rows, 'liability', $entityId, $query);
        $equity = $this->section($rows, 'equity', $entityId, $query);
        $earnings = array_sum(array_column($this->section($rows, 'income', $entityId, $query), 'amount_minor'))
            - array_sum(array_column($this->section($rows, 'expense', $entityId, $query), 'amount_minor'));

        return ['entity_id' => $entityId, 'as_of' => $asOf->toDateString(), 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'current_earnings_minor' => $earnings, 'total_assets_minor' => array_sum(array_column($assets, 'amount_minor')),
            'total_liabilities_minor' => array_sum(array_column($liabilities, 'amount_minor')), 'total_equity_minor' => array_sum(array_column($equity, 'amount_minor'))];
    }

    /**
     * One account's journal lines in a date range with opening and closing balances on its normal side, optionally only the lines carrying
     * $value in the column dimension $dimension (a null or empty value selects lines without that dimension).
     *
     * @return array{account: array{id: string, code: string, name: string, type: string, normal_side: string}|null, from: string|null, to: string, opening_minor: int, closing_minor: int,
     *     lines: list<array{journal_id: string, journal_number: string|null, posting_date: string, description: string|null, memo: string|null, debit_minor: int, credit_minor: int, url: string}>}
     */
    public function accountActivity(string $entityId, string $accountId, ?CarbonImmutable $from, CarbonImmutable $to, ?string $dimension = null, ?string $value = null): array
    {
        $column = $dimension === null ? null : self::dimensionColumn($dimension);
        $account = DB::table('accounts')->where('id', $accountId)->where('entity_id', $entityId)->first(['id', 'code', 'name', 'type', 'normal_side']);
        $empty = ['account' => null, 'from' => $from?->toDateString(), 'to' => $to->toDateString(), 'opening_minor' => 0, 'closing_minor' => 0, 'lines' => []];
        if ($account === null) {
            return $empty;
        }
        $sign = $account->normal_side === 'debit' ? 1 : -1;
        $opening = $from === null ? 0 : $sign * (int) $this->filtered($this->lines($entityId), $column, $value)->where('l.account_id', $accountId)->where('j.posting_date', '<', $from->toDateString())
            ->selectRaw("coalesce(sum(case when l.side = 'debit' then l.amount_minor else -l.amount_minor end), 0) as b")->value('b');
        $rows = $this->filtered($this->lines($entityId), $column, $value)->where('l.account_id', $accountId)->where('j.posting_date', '<=', $to->toDateString())
            ->when($from !== null, fn (Builder $q) => $q->where('j.posting_date', '>=', $from?->toDateString()))
            ->orderBy('j.posting_date')->orderBy('j.id')->orderBy('l.line_no')
            ->get(['j.id', 'j.number', 'j.posting_date', 'j.description', 'l.memo', 'l.side', 'l.amount_minor']);

        $lines = [];
        $closing = $opening;
        foreach ($rows as $row) {
            $amount = (int) $row->amount_minor;
            $debit = $row->side === 'debit' ? $amount : 0;
            $credit = $row->side === 'credit' ? $amount : 0;
            $closing += $sign * ($debit - $credit);
            $lines[] = ['journal_id' => (string) $row->id, 'journal_number' => $row->number === null ? null : (string) $row->number, 'posting_date' => (string) $row->posting_date,
                'description' => $row->description === null ? null : (string) $row->description, 'memo' => $row->memo === null ? null : (string) $row->memo,
                'debit_minor' => $debit, 'credit_minor' => $credit, 'url' => SourceJournalQuery::JOURNAL_URL.$row->id];
        }

        return ['account' => ['id' => (string) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'type' => (string) $account->type,
            'normal_side' => (string) $account->normal_side], 'from' => $from?->toDateString(), 'to' => $to->toDateString(), 'opening_minor' => $opening, 'closing_minor' => $closing, 'lines' => $lines];
    }

    /**
     * Movement in a date range of the accounts mapped to $roleCode (primary book, on `to`), on their normal side, split by a column dimension
     * (key '' = lines without it). Used for ratios over the GL such as loss ratio.
     *
     * @return array{account_ids: list<string>, by_dimension: array<string, int>}
     *
     * @throws \InvalidArgumentException for a dimension without its own journal_lines column
     */
    public function roleMovementByDimension(string $entityId, string $roleCode, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $column = self::dimensionColumn($dimension);
        $accountIds = $this->roleAccounts($entityId, $roleCode, $to);
        $rows = $this->lines($entityId)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.account_id', $accountIds)
            ->whereBetween('j.posting_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy($column)->selectRaw("{$column}::text as dimension_value, sum(case when l.side = a.normal_side then l.amount_minor else -l.amount_minor end) as movement")->get();
        $byDimension = [];
        foreach ($rows as $row) {
            $byDimension[(string) ($row->dimension_value ?? '')] = (int) $row->movement;
        }

        return ['account_ids' => $accountIds, 'by_dimension' => $byDimension];
    }

    /**
     * Balance at the end of $asOf of the accounts mapped to $roleCode (primary book, on $asOf), on their normal side, split by a column dimension
     * (key '' = lines without it). Used to reconcile a report built from business rows to its control account, e.g. unearned premium.
     *
     * @return array{account_ids: list<string>, balance_minor: int, by_dimension: array<string, int>}
     *
     * @throws \InvalidArgumentException for a dimension without its own journal_lines column
     */
    public function roleBalanceByDimension(string $entityId, string $roleCode, CarbonImmutable $asOf, string $dimension): array
    {
        $column = self::dimensionColumn($dimension);
        $accountIds = $this->roleAccounts($entityId, $roleCode, $asOf);
        $rows = $this->lines($entityId)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.account_id', $accountIds)
            ->where('j.posting_date', '<=', $asOf->toDateString())
            ->groupBy($column)->selectRaw("{$column}::text as dimension_value, sum(case when l.side = a.normal_side then l.amount_minor else -l.amount_minor end) as balance")->get();
        $byDimension = [];
        foreach ($rows as $row) {
            $byDimension[(string) ($row->dimension_value ?? '')] = (int) $row->balance;
        }

        return ['account_ids' => $accountIds, 'balance_minor' => array_sum($byDimension), 'by_dimension' => $byDimension];
    }

    /** @return list<string> accounts mapped to the role in the primary book on the date */
    private function roleAccounts(string $entityId, string $roleCode, CarbonImmutable $on): array
    {
        return array_values(DB::table('account_role_mappings as m')->join('books as b', 'b.id', '=', 'm.book_id')->where('b.is_primary', true)
            ->where('m.entity_id', $entityId)->where('m.role_code', $roleCode)->where('m.effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $on->toDateString()))
            ->pluck('m.account_id')->map(fn ($id): string => (string) $id)->all());
    }

    /** @return literal-string */
    private static function dimensionColumn(string $dimension): string
    {
        return match ($dimension) {
            'branch' => 'l.dim_branch', 'product' => 'l.dim_product', 'agent' => 'l.dim_agent', 'policy' => 'l.dim_policy', 'claim' => 'l.dim_claim',
            'customer' => 'l.dim_customer', 'channel' => 'l.dim_channel', 'lob' => 'l.dim_lob',
            default => throw new \InvalidArgumentException("Dimension '{$dimension}' cannot be reported on."),
        };
    }

    /** @param literal-string|null $column */
    private function filtered(Builder $query, ?string $column, ?string $value): Builder
    {
        if ($column === null) {
            return $query;
        }

        return $value === null || $value === '' ? $query->whereNull($column) : $query->where($column, $value);
    }

    /**
     * @param list<string> $types
     * @return list<object{account_id: string, code: string, name: string, type: string, balance: int|string}>
     */
    private function accountBalances(string $entityId, array $types, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<object{account_id: string, code: string, name: string, type: string, balance: int|string}> */
        return $this->lines($entityId)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', $types)
            ->when($from !== null, fn (Builder $q) => $q->where('j.posting_date', '>=', $from?->toDateString()))->where('j.posting_date', '<=', $to->toDateString())
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')
            ->selectRaw("a.id as account_id, a.code, a.name, a.type, sum(case when l.side = a.normal_side then l.amount_minor else -l.amount_minor end) as balance")
            ->get()->all();
    }

    /**
     * @param list<object{account_id: string, code: string, name: string, type: string, balance: int|string}> $rows
     * @return list<array{account_id: string, code: string, name: string, amount_minor: int, url: string}>
     */
    private function section(array $rows, string $type, string $entityId, string $query): array
    {
        $section = [];
        foreach ($rows as $row) {
            if ($row->type === $type && (int) $row->balance !== 0) {
                $section[] = ['account_id' => $row->account_id, 'code' => $row->code, 'name' => $row->name, 'amount_minor' => (int) $row->balance,
                    'url' => "/api/reports/accounts/{$row->account_id}/activity?entity_id={$entityId}{$query}"];
            }
        }

        return $section;
    }

    private function lines(string $entityId): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('books as b', 'b.id', '=', 'j.book_id')
            ->where('b.is_primary', true)->where('j.entity_id', $entityId)->whereIn('j.status', self::LEDGER_STATUSES);
    }
}
