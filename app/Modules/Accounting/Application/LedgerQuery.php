<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read side. Balances are ALWAYS derived from posted journal lines — no stored balance table (design §6.1). */
final class LedgerQuery
{
    /** Signed balance in minor units, positive = debit balance. */
    public function balance(string $accountId, string $bookId, CarbonImmutable $asOf): int
    {
        $row = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $accountId)->where('j.book_id', $bookId)->where('j.status', 'posted')
            ->where('j.posting_date', '<=', $asOf->toDateString())
            ->selectRaw("coalesce(sum(case when l.side='debit' then l.amount_minor else -l.amount_minor end),0) as bal")->first();
        return (int) ($row->bal ?? 0);
    }

    /** @return list<array{account_id:string, code:string, name:string, type:string, debit:int, credit:int}> */
    public function trialBalance(string $entityId, string $bookId, CarbonImmutable $asOf): array
    {
        $rows = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.entity_id', $entityId)->where('j.book_id', $bookId)->where('j.status', 'posted')->where('j.posting_date', '<=', $asOf->toDateString())
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')
            ->selectRaw("a.id as account_id, a.code, a.name, a.type,
                coalesce(sum(case when l.side='debit' then l.amount_minor else 0 end),0) as debit,
                coalesce(sum(case when l.side='credit' then l.amount_minor else 0 end),0) as credit")->get();
        return $rows->map(fn ($r) => ['account_id' => (string) $r->account_id, 'code' => (string) $r->code, 'name' => (string) $r->name,
            'type' => (string) $r->type, 'debit' => (int) $r->debit, 'credit' => (int) $r->credit])->all();
    }
}
