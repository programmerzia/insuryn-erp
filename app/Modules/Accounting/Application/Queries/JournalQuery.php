<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Accounting\Domain\Enums\Side;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read models for journals (design §2.2): the journal list and one journal with its lines, source event
 * and correction chain (§2.3: reverses_journal_id / reversed_by_journal_id / corrects_journal_id).
 * Amounts are minor units; presentation happens in the HTTP layer.
 */
final class JournalQuery
{
    public const PAGE_SIZE = 25;

    /**
     * @param array{status?: string|null} $filters
     * @return LengthAwarePaginator<int, object{id: string, number: string|null, status: string, kind: string, posting_date: string, description: string|null, currency: string, total_minor: int|string}>
     */
    public function list(string $entityId, array $filters, int $page): LengthAwarePaginator
    {
        $query = DB::table('journals as j')
            ->where('j.entity_id', $entityId)
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('j.status', $status))
            ->select(['j.id', 'j.number', 'j.status', 'j.kind', 'j.posting_date', 'j.description', 'j.currency'])
            ->selectSub(DB::table('journal_lines as l')->whereColumn('l.journal_id', 'j.id')->where('l.side', Side::Debit->value)
                ->selectRaw('coalesce(sum(l.amount_minor), 0)'), 'total_minor')
            ->orderByDesc('j.posting_date')->orderByDesc('j.id');

        /** @var LengthAwarePaginator<int, object{id: string, number: string|null, status: string, kind: string, posting_date: string, description: string|null, currency: string, total_minor: int|string}> */
        return $query->paginate(self::PAGE_SIZE, ['*'], 'page', $page);
    }

    /**
     * One journal with its lines, source event and correction chain, or null when it is not visible in this tenant.
     *
     * @return array{
     *   journal: array{id: string, number: string|null, status: string, kind: string, transaction_date: string, posting_date: string, effective_date: string,
     *     description: string|null, reason: string|null, currency: string, posted_at: string|null, source_type: string|null, source_id: string|null,
     *     posting_rule_code: string|null, posting_rule_version: int|null},
     *   lines: list<array{line_no: int, account_code: string, account_name: string, side: string, amount_minor: int, currency: string, role_code: string|null, memo: string|null}>,
     *   event: array{id: string, event_type: string}|null,
     *   reverses: array{id: string, number: string|null, status: string, kind: string}|null,
     *   reversed_by: array{id: string, number: string|null, status: string, kind: string}|null,
     *   corrects: array{id: string, number: string|null, status: string, kind: string}|null,
     *   corrections: list<array{id: string, number: string|null, status: string, kind: string}>
     * }|null
     */
    public function detail(string $journalId): ?array
    {
        $j = DB::table('journals')->where('id', $journalId)->first();
        if ($j === null) {
            return null;
        }
        $event = DB::table('journal_batches as b')->join('accounting_events as e', 'e.id', '=', 'b.accounting_event_id')->where('b.id', $j->batch_id)
            ->first(['e.id', 'e.event_type']);

        return [
            'journal' => [
                'id' => (string) $j->id, 'number' => self::nullableString($j->number), 'status' => (string) $j->status, 'kind' => (string) $j->kind,
                'transaction_date' => (string) $j->transaction_date, 'posting_date' => (string) $j->posting_date, 'effective_date' => (string) $j->effective_date,
                'description' => self::nullableString($j->description), 'reason' => self::nullableString($j->reason), 'currency' => (string) $j->currency,
                'posted_at' => self::nullableString($j->posted_at), 'source_type' => self::nullableString($j->source_type), 'source_id' => self::nullableString($j->source_id),
                'posting_rule_code' => self::nullableString($j->posting_rule_code), 'posting_rule_version' => $j->posting_rule_version === null ? null : (int) $j->posting_rule_version,
            ],
            'lines' => $this->lines($journalId),
            'event' => $event === null ? null : ['id' => (string) $event->id, 'event_type' => (string) $event->event_type],
            'reverses' => $this->reference(self::nullableString($j->reverses_journal_id)),
            'reversed_by' => $this->reference(self::nullableString($j->reversed_by_journal_id)),
            'corrects' => $this->reference(self::nullableString($j->corrects_journal_id)),
            'corrections' => array_values(DB::table('journals')->where('corrects_journal_id', $journalId)->orderBy('posting_date')
                ->get(['id', 'number', 'status', 'kind'])->map(fn (object $row): array => self::referenceRow($row))->all()),
        ];
    }

    /** @return list<array{line_no: int, account_code: string, account_name: string, side: string, amount_minor: int, currency: string, role_code: string|null, memo: string|null}> */
    private function lines(string $journalId): array
    {
        return array_values(DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journalId)->orderBy('l.line_no')
            ->get(['l.line_no', 'a.code as account_code', 'a.name as account_name', 'l.side', 'l.amount_minor', 'l.currency', 'l.role_code', 'l.memo'])
            ->map(fn (object $l): array => [
                'line_no' => (int) $l->line_no, 'account_code' => (string) $l->account_code, 'account_name' => (string) $l->account_name,
                'side' => (string) $l->side, 'amount_minor' => (int) $l->amount_minor, 'currency' => (string) $l->currency,
                'role_code' => self::nullableString($l->role_code), 'memo' => self::nullableString($l->memo),
            ])->all());
    }

    /** @return array{id: string, number: string|null, status: string, kind: string}|null */
    private function reference(?string $journalId): ?array
    {
        $row = $journalId === null ? null : DB::table('journals')->where('id', $journalId)->first(['id', 'number', 'status', 'kind']);

        return $row === null ? null : self::referenceRow($row);
    }

    /** @return array{id: string, number: string|null, status: string, kind: string} */
    private static function referenceRow(object $row): array
    {
        /** @var object{id: string, number: string|null, status: string, kind: string} $row */
        return ['id' => $row->id, 'number' => $row->number, 'status' => $row->status, 'kind' => $row->kind];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (is_scalar($value) ? (string) $value : null);
    }
}
