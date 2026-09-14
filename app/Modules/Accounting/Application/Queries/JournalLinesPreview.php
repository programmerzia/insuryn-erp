<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Platform\Money\MinorUnits;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-04: a journal's lines as the approver sees them before deciding — account, debit and credit, formatted — for a manual journal waiting for
 * approval, or mirrored for the reversal a request would post.
 */
final class JournalLinesPreview
{
    /** @return list<array{account: string, name: string, debit: string|null, credit: string|null}> */
    public static function lines(string $journalId, bool $mirrored = false): array
    {
        $currency = (string) DB::table('journals')->where('id', $journalId)->value('currency');
        $lines = [];
        foreach (DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journalId)->orderBy('l.line_no')
            ->get(['a.code', 'a.name', 'l.side', 'l.amount_minor']) as $line) {
            $debit = ($line->side === 'debit') !== $mirrored;
            $amount = MinorUnits::format((int) $line->amount_minor, $currency);
            $lines[] = ['account' => (string) $line->code, 'name' => (string) $line->name, 'debit' => $debit ? $amount : null, 'credit' => $debit ? null : $amount];
        }

        return $lines;
    }
}
