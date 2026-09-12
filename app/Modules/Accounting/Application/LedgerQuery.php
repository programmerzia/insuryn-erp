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

    private function ledgerLines(string $bookId, CarbonImmutable $asOf): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.book_id', $bookId)
            ->whereIn('j.status', self::LEDGER_STATUSES)
            ->where('j.posting_date', '<=', $asOf->toDateString());
    }
}
