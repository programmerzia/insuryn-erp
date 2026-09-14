<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

/**
 * ASSUMPTION: A-197 (GA-31) — the reference a journal is known by before it posts. The gapless JV number is still given only when it posts (so a rejected journal
 * never leaves a gap); until then it reads `MJ-` and the last six characters of its id, which the maker and the approver can quote.
 */
final class ProvisionalReference
{
    public static function for(string $journalId): string
    {
        return 'MJ-'.strtoupper(substr(str_replace('-', '', $journalId), -6));
    }
}
