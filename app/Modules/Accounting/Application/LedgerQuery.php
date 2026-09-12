<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Enums\JournalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read side. Balances are ALWAYS derived from posted journal lines — no stored balance table (design §6.1). */
final class LedgerQuery
{
    /**
     * Journals that are ledger history. A reversed journal stays in the ledger (design §2.3): its
     * reversal is a separate posted journal that offsets it from the reversal date onwards.
     */
    private const LEDGER_STATUSES = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

    /** Signed balance in minor units, positive = debit balance. */
    public function balance(string $accountId, string $bookId, CarbonImmutable $asOf): int
    {
        $balance = $this->ledgerLines($bookId, $asOf)
            ->where('l.account_id', $accountId)
            ->selectRaw("coalesce(sum(case when l.side='debit' then l.amount_minor else -l.amount_minor end),0) as bal")
            ->value('bal');

        return (int) $balance;
    }

    /** @return list<array{account_id:string, code:string, name:string, type:string, debit:int, credit:int}> */
    public function trialBalance(string $entityId, string $bookId, CarbonImmutable $asOf): array
    {
        $rows = $this->ledgerLines($bookId, $asOf)
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.entity_id', $entityId)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')
            ->selectRaw("a.id as account_id, a.code, a.name, a.type,
                coalesce(sum(case when l.side='debit' then l.amount_minor else 0 end),0) as debit,
                coalesce(sum(case when l.side='credit' then l.amount_minor else 0 end),0) as credit")
            ->get();

        $trialBalance = [];
        foreach ($rows as $row) {
            /** @var object{account_id: string, code: string, name: string, type: string, debit: int|string, credit: int|string} $row */
            $trialBalance[] = ['account_id' => $row->account_id, 'code' => $row->code, 'name' => $row->name,
                'type' => $row->type, 'debit' => (int) $row->debit, 'credit' => (int) $row->credit];
        }

        return $trialBalance;
    }

    /**
     * Balance of a set of accounts on their normal side (credit-normal accounts count credits positive), split by the value lines carry in
     * $dimension; lines without it are grouped by journal so a stray posting can be traced (design §6.3 exception drill-down).
     *
     * @param list<string> $accountIds
     * @return array{total: int, by_dimension: array<string, int>, unattributed_by_journal: array<string, int>}
     */
    public function normalBalanceByDimension(array $accountIds, string $bookId, CarbonImmutable $asOf, string $dimension): array
    {
        [$column, $bindings] = self::dimensionExpression($dimension);
        $rows = $this->ledgerLines($bookId, $asOf)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.account_id', $accountIds)
            ->groupByRaw("dimension_value, journal_ref")
            ->selectRaw("{$column} as dimension_value, case when {$column} is null then l.journal_id end as journal_ref,
                coalesce(sum(case when l.side = a.normal_side then l.amount_minor else -l.amount_minor end), 0) as balance", [...$bindings, ...$bindings])
            ->get();

        $result = ['total' => 0, 'by_dimension' => [], 'unattributed_by_journal' => []];
        foreach ($rows as $row) {
            /** @var object{dimension_value: string|null, journal_ref: string|null, balance: int|string} $row */
            $balance = (int) $row->balance;
            $result['total'] += $balance;
            if ($row->dimension_value !== null) {
                $result['by_dimension'][$row->dimension_value] = $balance;
            } elseif ($balance !== 0) {
                $result['unattributed_by_journal'][(string) $row->journal_ref] = $balance;
            }
        }

        return $result;
    }

    /**
     * SQL for a line's value in a dimension: its dim_* column (the list in LineDimensions), or the dims_ext key (bound, never interpolated).
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private static function dimensionExpression(string $dimension): array
    {
        return match ($dimension) {
            'branch' => ['l.dim_branch::text', []], 'product' => ['l.dim_product::text', []], 'lob' => ['l.dim_lob::text', []],
            'channel' => ['l.dim_channel::text', []], 'agent' => ['l.dim_agent::text', []], 'policy' => ['l.dim_policy::text', []],
            'claim' => ['l.dim_claim::text', []], 'cost_centre' => ['l.dim_cost_centre::text', []], 'employee' => ['l.dim_employee::text', []],
            'customer' => ['l.dim_customer::text', []], 'reinsurer' => ['l.dim_reinsurer::text', []],
            default => ["l.dims_ext->>?", [$dimension]],
        };
    }

    private function ledgerLines(string $bookId, CarbonImmutable $asOf): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.book_id', $bookId)
            ->whereIn('j.status', self::LEDGER_STATUSES)
            ->where('j.posting_date', '<=', $asOf->toDateString());
    }
}
