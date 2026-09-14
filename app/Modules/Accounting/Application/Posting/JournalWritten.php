<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

/**
 * Gap fix GA-04: raised in-process, inside the posting transaction, each time the JournalWriter marks a journal posted — by the posting engine, a
 * reversal, a manual journal's final approval or the year-end close. The journal preview (App\Http\Preview\PreviewJournal) listens to it to show
 * journals an action writes directly, not only those it submits as accounting events. Not a domain event for business modules: they react to
 * accounting events and the outbox.
 */
final readonly class JournalWritten
{
    public function __construct(public string $journalId) {}
}
