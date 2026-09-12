<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Posting\DraftLine;
use App\Modules\Accounting\Application\Posting\JournalDraft;
use App\Modules\Accounting\Application\Posting\JournalWriter;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §2.3: reversal = mirrored lines, new journal, links both ways; original never edited.
 * A reversal is a posting into the period of its date, so the period rules apply (non-negotiable #3).
 */
final class ReversalService
{
    public function __construct(
        private readonly PostingContextLoader $contexts,
        private readonly JournalWriter $writer,
        private readonly Audit $audit,
    ) {}

    /** @throws PostingFailedException NOT_POSTED, REASON_REQUIRED, PERIOD_MISSING, PERIOD_CLOSED, PERIOD_SOFT_LOCKED */
    public function reverse(Journal $original, CarbonImmutable $on, string $reason, string $actorUserId, bool $actorMayPostSoftLocked = false): Journal
    {
        if ($reason === '') {
            throw new PostingFailedException('REASON_REQUIRED', 'Reversal requires a reason');
        }

        return DB::transaction(function () use ($original, $on, $reason, $actorUserId, $actorMayPostSoftLocked): Journal {
            $this->lockPostedJournal($original);
            $period = $this->contexts->period($original->entity_id, $original->book_id, $on, $actorMayPostSoftLocked);
            $draft = JournalDraft::balanced('Reversal of '.$original->number, $this->mirroredLines($original));

            $reversal = $this->writer->post([
                'entity_id' => $original->entity_id, 'book_id' => $original->book_id, 'batch_id' => $original->batch_id, 'period_id' => $period->id,
                'transaction_date' => $on, 'posting_date' => $on, 'effective_date' => $on,
                'kind' => JournalKind::Reversal->value, 'reverses_journal_id' => $original->id, 'original_transaction_id' => $original->source_id,
                'reason' => $reason, 'source_type' => $original->source_type, 'source_id' => $original->source_id,
                'currency' => $original->currency, 'created_by' => $actorUserId, 'description' => 'Reversal of '.$original->number,
            ], $draft);
            // Only these two columns may change on a posted journal (DB trigger enforces).
            $original->forceFill(['status' => JournalStatus::Reversed->value, 'reversed_by_journal_id' => $reversal->id])->save();
            $this->audit->record('journal.reversed', AuditSubject::of('journal', $original->id),
                ['status' => JournalStatus::Posted->value], ['status' => JournalStatus::Reversed->value, 'reversed_by_journal_id' => $reversal->id],
                $reason, 'accounting.reverse_journal', Actor::user($actorUserId));

            return $reversal;
        });
    }

    /**
     * Reads the committed status under a row lock: the caller's copy may be stale, and a concurrent
     * reversal of the same journal must wait and then see it reversed (reversed at most once, §2.3).
     */
    private function lockPostedJournal(Journal $original): void
    {
        $status = DB::table('journals')->where('id', $original->id)->lockForUpdate()->value('status');
        if ($status !== JournalStatus::Posted->value) {
            throw new PostingFailedException('NOT_POSTED', 'Only posted journals can be reversed');
        }
    }

    /** @return list<DraftLine> */
    private function mirroredLines(Journal $original): array
    {
        $lines = [];
        foreach ($original->lines as $line) {
            $lines[] = DraftLine::fromStoredLine($line)->mirrored();
        }

        return $lines;
    }
}
