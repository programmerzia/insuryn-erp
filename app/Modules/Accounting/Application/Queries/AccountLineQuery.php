<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side for business modules that reconcile an account line by line (bank matching, slice 1A.6). Lines come from journals
 * that stand posted: reversed journals and their reversals cancel out and are left out. Amounts are signed, debit positive.
 */
final class AccountLineQuery
{
    public function account(string $accountId): ?AccountView
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $accountId) !== 1) {
            return null;
        }
        $account = DB::table('accounts')->where('id', $accountId)->first(['id', 'entity_id', 'code', 'name', 'type', 'is_postable', 'is_control', 'currency', 'status']);

        return $account === null ? null : new AccountView((string) $account->id, (string) $account->entity_id, (string) $account->code, (string) $account->name,
            (string) $account->type, (bool) $account->is_postable, (bool) $account->is_control, $account->currency === null ? null : (string) $account->currency, (string) $account->status);
    }

    /**
     * @return list<array{journal_line_id: string, journal_id: string, journal_number: string|null, posting_date: string, amount_minor: int, currency: string,
     *     reference: string|null, receipt_number: string|null, source_type: string|null, source_id: string|null}>
     */
    public function postedLines(string $accountId, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->select($this->base($accountId)
            ->when($from !== null, fn (Builder $q) => $q->where('j.posting_date', '>=', $from?->toDateString()))
            ->where('j.posting_date', '<=', $to->toDateString()));
    }

    /**
     * The subset of $journalLineIds that are standing posted lines on the account.
     *
     * @param list<string> $journalLineIds
     * @return list<array{journal_line_id: string, journal_id: string, journal_number: string|null, posting_date: string, amount_minor: int, currency: string,
     *     reference: string|null, receipt_number: string|null, source_type: string|null, source_id: string|null}>
     */
    public function postedLinesByIds(string $accountId, array $journalLineIds): array
    {
        return $journalLineIds === [] ? [] : $this->select($this->base($accountId)->whereIn('l.id', $journalLineIds));
    }

    private function base(string $accountId): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->leftJoin('journal_batches as b', 'b.id', '=', 'j.batch_id')->leftJoin('accounting_events as e', 'e.id', '=', 'b.accounting_event_id')
            ->where('l.account_id', $accountId)->where('j.status', 'posted')->whereNull('j.reverses_journal_id');
    }

    /**
     * @return list<array{journal_line_id: string, journal_id: string, journal_number: string|null, posting_date: string, amount_minor: int, currency: string,
     *     reference: string|null, receipt_number: string|null, source_type: string|null, source_id: string|null}>
     */
    private function select(Builder $query): array
    {
        $rows = $query->orderBy('j.posting_date')->orderBy('l.id')
            ->get(['l.id', 'l.journal_id', 'j.number', 'j.posting_date', 'l.side', 'l.amount_minor', 'l.currency', 'j.source_type', 'j.source_id',
                // Gap fix GA-27: a journal line without an event (a manual journal for bank charges) is referenced by its line memo.
                DB::raw("coalesce(e.payload->>'reference', l.memo) as reference"), DB::raw("e.payload->>'receipt_number' as receipt_number")]);
        $lines = [];
        foreach ($rows as $row) {
            $amount = (int) $row->amount_minor;
            $lines[] = ['journal_line_id' => (string) $row->id, 'journal_id' => (string) $row->journal_id, 'journal_number' => self::nullableString($row->number),
                'posting_date' => (string) $row->posting_date, 'amount_minor' => $row->side === 'debit' ? $amount : -$amount, 'currency' => (string) $row->currency,
                'reference' => self::nullableString($row->reference), 'receipt_number' => self::nullableString($row->receipt_number),
                'source_type' => self::nullableString($row->source_type), 'source_id' => self::nullableString($row->source_id)];
        }

        return $lines;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
