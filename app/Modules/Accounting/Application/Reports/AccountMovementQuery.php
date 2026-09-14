<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reports;

use App\Modules\Accounting\Domain\Enums\JournalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum v2 PD-18: actual movement of income and expense accounts per account, branch and calendar month, on each account's normal side, from
 * posted journals of the primary book — what budgets are compared with. The year-end closing journal is left out, so a closed year still shows its actuals.
 */
final class AccountMovementQuery
{
    private const LEDGER_STATUSES = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

    /**
     * @param list<string> $types account types (income, expense)
     * @return list<array{account_id: string, branch_id: string|null, month: string, amount_minor: int}> month as Y-m
     */
    public function byAccountBranchMonth(string $entityId, CarbonImmutable $from, CarbonImmutable $to, array $types = ['income', 'expense']): array
    {
        $rows = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('books as b', 'b.id', '=', 'j.book_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('b.is_primary', true)->where('j.entity_id', $entityId)->whereIn('j.status', self::LEDGER_STATUSES)->where('j.kind', '<>', 'closing')
            ->whereIn('a.type', $types)->whereBetween('j.posting_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.account_id', 'l.dim_branch')->groupByRaw("to_char(j.posting_date, 'YYYY-MM')")
            ->selectRaw("l.account_id, l.dim_branch::text as branch_id, to_char(j.posting_date, 'YYYY-MM') as month, sum(case when l.side = a.normal_side then l.amount_minor else -l.amount_minor end) as amount")
            ->get();

        $movements = [];
        foreach ($rows as $row) {
            $movements[] = ['account_id' => (string) $row->account_id, 'branch_id' => $row->branch_id === null ? null : (string) $row->branch_id, 'month' => (string) $row->month, 'amount_minor' => (int) $row->amount];
        }

        return $movements;
    }
}
