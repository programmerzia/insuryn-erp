<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Drill-down from business objects to the journals posted for them (design §2.2 journals.source_type / source_id). Only ledger
 * journals (posted, or reversed and still in history) are returned, oldest first, each with the journal page URL.
 */
final class SourceJournalQuery
{
    public const JOURNAL_URL = '/accounting/journals/';

    /**
     * @param array<array-key, string> $sourceIds
     * @return array<string, list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>> source id → journals
     */
    public function bySource(string $sourceType, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $journals = [];
        $rows = DB::table('journals')->where('source_type', $sourceType)->whereIn('source_id', array_values(array_unique($sourceIds)))
            ->whereIn('status', ['posted', 'reversed'])->orderBy('posting_date')->orderBy('id')->get(['id', 'number', 'posting_date', 'status', 'source_id']);
        foreach ($rows as $row) {
            $journals[(string) $row->source_id][] = ['journal_id' => (string) $row->id, 'journal_number' => $row->number === null ? null : (string) $row->number,
                'posting_date' => (string) $row->posting_date, 'status' => (string) $row->status, 'url' => self::JOURNAL_URL.$row->id];
        }

        return $journals;
    }
}
