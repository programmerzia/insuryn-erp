<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Accounting\Application\Posting\DraftLine;
use App\Modules\Accounting\Application\Posting\JournalDraft;
use App\Modules\Accounting\Application\Posting\JournalWriter;
use App\Modules\Accounting\Application\Posting\LineDimensions;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Exceptions\ManualJournalException;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Authorization\SodViolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.2 for kind manual | adjustment | opening:
 *   draft ─submit─▶ pending_approval ─approve─▶ posted   (reject ─▶ cancelled)
 * D-11: the checker is never the maker. When an approval policy matches (object type `journal`, amount =
 * total debits, attribute kind) every policy step must approve; otherwise one checker holding
 * accounting.approve_journal approves. Posting goes through the kernel JournalWriter.
 * §6.2 INVARIANT: lines on control accounts need kind=adjustment, a reason, and accounting.post_to_control
 * for the maker and for the approver who posts.
 */
final class ManualJournalService
{
    private const MANUAL_KINDS = [JournalKind::Manual, JournalKind::Adjustment, JournalKind::Opening];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly PostingContextLoader $contexts,
        private readonly JournalWriter $writer,
        private readonly Audit $audit,
    ) {}

    public function create(ManualJournalRequest $request, string $makerId): Journal
    {
        $this->permissions->authorize($makerId, 'accounting.create_manual_journal', AuthorizationScope::entity($request->entityId));
        if (! in_array($request->kind, self::MANUAL_KINDS, true)) {
            throw new ManualJournalException('INVALID_KIND', "A manual journal cannot be of kind {$request->kind->value}.");
        }
        if (count($request->lines) < 2) {
            throw new ManualJournalException('TOO_FEW_LINES', 'A manual journal needs at least two lines.');
        }
        $accounts = $this->postableAccounts($request);
        $this->assertControlAccountsAllowed($accounts, $request->kind, $request->reason, $makerId);
        $draft = JournalDraft::balanced('Manual journal', $this->draftLines($request));

        $book = Book::query()->where('is_primary', true)->firstOrFail();
        $period = $this->contexts->period($request->entityId, $book->id, $request->transactionDate, true); // soft-lock is checked at posting

        return DB::transaction(function () use ($request, $makerId, $draft, $book, $period): Journal {
            $journal = $this->writer->draft([
                'entity_id' => $request->entityId, 'book_id' => $book->id, 'period_id' => $period->id,
                'transaction_date' => $request->transactionDate, 'posting_date' => $request->transactionDate, 'effective_date' => $request->transactionDate,
                'kind' => $request->kind->value, 'reason' => $request->reason, 'description' => $request->description,
                'currency' => $request->currency, 'created_by' => $makerId, 'source_type' => 'manual_journal',
            ], $draft);
            $this->audit->record('journal.created', AuditSubject::of('journal', $journal->id), null,
                ['kind' => $request->kind->value, 'amount_minor' => $this->debitTotal($draft)], $request->reason, 'accounting.create_manual_journal', Actor::user($makerId));

            return $journal;
        });
    }

    /** Submits the maker's draft; returns the approval id when a policy applies, null for single-checker approval. */
    public function submit(string $journalId, string $makerId): ?string
    {
        return DB::transaction(function () use ($journalId, $makerId): ?string {
            $journal = $this->lockJournal($journalId, JournalStatus::Draft);
            if ($journal->getAttribute('created_by') !== $makerId) {
                throw new ManualJournalException('NOT_MAKER', "Only the maker can submit journal {$journalId}.");
            }
            $facts = new ApprovalFacts($this->storedDebitTotal($journalId), ['kind' => $journal->kind->value]);
            $approvalId = $this->approvals->request('journal', $journalId, $facts, $makerId, $journal->transaction_date);
            $journal->forceFill(['status' => JournalStatus::PendingApproval->value])->save();
            $this->audit->record('journal.submitted', AuditSubject::of('journal', $journalId), ['status' => 'draft'], ['status' => 'pending_approval', 'approval_id' => $approvalId],
                null, 'accounting.create_manual_journal', Actor::user($makerId));

            return $approvalId;
        });
    }

    /** One approval decision; posts the journal when it is the final one. Returns the journal as it now stands. */
    public function approve(string $journalId, string $checkerId, ?string $reason = null): Journal
    {
        DB::transaction(function () use ($journalId, $checkerId, $reason): void {
            $journal = $this->lockJournal($journalId, JournalStatus::PendingApproval);
            $approvalId = $this->approvals->pendingFor('journal', $journalId);
            if ($approvalId !== null) {
                $this->approvals->decide($approvalId, $checkerId, Decision::Approved, $reason); // the handler posts on the final step

                return;
            }
            $this->assertSingleChecker($journal, $checkerId);
            $this->audit->record('journal.approved', AuditSubject::of('journal', $journalId), ['status' => 'pending_approval'], ['status' => 'approved'],
                $reason, 'accounting.approve_journal', Actor::user($checkerId));
            $this->postApproved($journalId, $checkerId);
        });

        return Journal::query()->findOrFail($journalId);
    }

    public function reject(string $journalId, string $checkerId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ManualJournalException('REASON_REQUIRED', 'Rejecting a journal requires a reason.');
        }
        DB::transaction(function () use ($journalId, $checkerId, $reason): void {
            $journal = $this->lockJournal($journalId, JournalStatus::PendingApproval);
            $approvalId = $this->approvals->pendingFor('journal', $journalId);
            if ($approvalId !== null) {
                $this->approvals->decide($approvalId, $checkerId, Decision::Rejected, $reason); // the handler cancels

                return;
            }
            $this->assertSingleChecker($journal, $checkerId);
            $this->cancel($journalId, $checkerId, $reason);
        });
    }

    /** Final approval reached (single checker or last policy step): post through the kernel journal path. */
    public function postApproved(string $journalId, string $approverId): Journal
    {
        return DB::transaction(function () use ($journalId, $approverId): Journal {
            $journal = $this->lockJournal($journalId, JournalStatus::PendingApproval);
            $accounts = Account::query()->whereIn('id', $journal->lines()->pluck('account_id'))->get();
            $this->assertControlAccountsAllowed($accounts, $journal->kind, $journal->reason, $approverId);
            $this->contexts->period($journal->entity_id, $journal->book_id, $journal->posting_date,
                $this->permissions->has($approverId, 'accounting.post_in_soft_locked', AuthorizationScope::entity($journal->entity_id)));

            return $this->writer->postDraft($journal, $approverId);
        });
    }

    public function cancel(string $journalId, string $deciderId, string $reason): void
    {
        $journal = $this->lockJournal($journalId, JournalStatus::PendingApproval);
        $journal->forceFill(['status' => JournalStatus::Cancelled->value])->save();
        $this->audit->record('journal.rejected', AuditSubject::of('journal', $journalId), ['status' => 'pending_approval'], ['status' => 'cancelled'],
            $reason, 'accounting.approve_journal', Actor::user($deciderId));
    }

    private function assertSingleChecker(Journal $journal, string $checkerId): void
    {
        $this->permissions->authorize($checkerId, 'accounting.approve_journal', AuthorizationScope::entity($journal->entity_id));
        if ($journal->getAttribute('created_by') === $checkerId) {
            throw new SodViolation('SOD_CONFLICT', $checkerId, 'accounting.approve_journal', 'accounting.create_manual_journal', 'MAKER_CHECKER',
                "The maker of journal {$journal->id} cannot approve it.");
        }
        $this->sod->assert($checkerId, 'accounting.approve_journal', AuditSubject::of('journal', $journal->id));
    }

    private function lockJournal(string $journalId, JournalStatus $expected): Journal
    {
        $journal = Journal::query()->whereKey($journalId)->lockForUpdate()->first();
        if ($journal === null || ! in_array($journal->kind, self::MANUAL_KINDS, true) || $journal->status !== $expected) {
            throw new ManualJournalException('INVALID_STATUS', "Journal {$journalId} is not a {$expected->value} manual journal.");
        }

        return $journal;
    }

    /** @return Collection<int, Account> */
    private function postableAccounts(ManualJournalRequest $request): Collection
    {
        $ids = array_values(array_unique(array_map(fn (ManualJournalLine $line): string => $line->accountId, $request->lines)));
        $accounts = Account::query()->whereIn('id', $ids)->where('entity_id', $request->entityId)->get();
        if ($accounts->count() !== count($ids)) {
            throw new ManualJournalException('UNKNOWN_ACCOUNT', 'Every line must use an account of the journal\'s entity.');
        }
        foreach ($accounts as $account) {
            if (! $account->is_postable || $account->status !== 'active') {
                throw new ManualJournalException('ACCOUNT_NOT_POSTABLE', "Account {$account->code} does not accept postings.");
            }
        }

        return $accounts;
    }

    /** @param Collection<int, Account> $accounts */
    private function assertControlAccountsAllowed(Collection $accounts, JournalKind $kind, ?string $reason, string $actorId): void
    {
        $control = $accounts->first(fn (Account $account): bool => (bool) $account->is_control);
        if ($control === null) {
            return;
        }
        // Opening balances legitimately land on control accounts, under the same conditions as an adjustment.
        $allowed = in_array($kind, [JournalKind::Adjustment, JournalKind::Opening], true) && trim((string) $reason) !== ''
            && $this->permissions->has($actorId, 'accounting.post_to_control', AuthorizationScope::entity((string) $control->entity_id));
        if (! $allowed) {
            throw new ManualJournalException('CONTROL_ACCOUNT',
                "Control account {$control->code} accepts only adjustments with a reason by users holding accounting.post_to_control.");
        }
    }

    /** @return list<DraftLine> */
    private function draftLines(ManualJournalRequest $request): array
    {
        $lines = [];
        foreach ($request->lines as $index => $line) {
            if ($line->amountMinor <= 0) {
                throw new ManualJournalException('AMOUNT_NOT_POSITIVE', 'Line '.($index + 1).' needs a positive amount; the side carries the sign.');
            }
            $lines[] = new DraftLine($index + 1, $line->accountId, $line->side, $line->amountMinor, $request->currency, $line->amountMinor,
                null, $line->memo, LineDimensions::fromValues($line->dimensions));
        }

        return $lines;
    }

    private function debitTotal(JournalDraft $draft): int
    {
        return array_sum(array_map(fn (DraftLine $line): int => $line->side === Side::Debit ? $line->amountMinor : 0, $draft->lines));
    }

    private function storedDebitTotal(string $journalId): int
    {
        return (int) DB::table('journal_lines')->where('journal_id', $journalId)->where('side', Side::Debit->value)->sum('amount_minor');
    }
}
