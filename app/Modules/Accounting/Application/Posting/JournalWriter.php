<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Application\JournalNumberer;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place journal and journal_lines rows are written (CONTEXT.md non-negotiable #4), shared by
 * posting and reversal. Lines go in with one bulk insert through the query builder, which bypasses
 * Eloquent casts and hooks, so id, tenant_id, the side value and JSON dims_ext are set explicitly.
 * Every posted journal is audited in the same transaction (design §3.3 step 4).
 */
final class JournalWriter
{
    public function __construct(
        private readonly JournalNumberer $numberer,
        private readonly Audit $audit,
    ) {}

    /**
     * Inserts the journal as draft with its lines, then numbers it and marks it posted, all in the
     * caller's transaction. The DB triggers re-check balance, period and immutability on the flip.
     *
     * @param array<string, mixed> $header journal columns except status, number and posted_at; must include entity_id, book_id and posting_date
     */
    public function post(array $header, JournalDraft $draft): Journal
    {
        return $this->postDraft($this->draft($header, $draft), null);
    }

    /**
     * Inserts the journal as draft with its lines (manual journals wait here for approval). Drafts carry
     * no number and do not count in the ledger.
     *
     * @param array<string, mixed> $header as for post()
     */
    public function draft(array $header, JournalDraft $draft): Journal
    {
        $journal = Journal::query()->create($header + ['status' => JournalStatus::Draft->value]);
        DB::table('journal_lines')->insert($this->rows($journal, $draft));

        return $journal;
    }

    /** Numbers a draft or approved journal and marks it posted; $approvedBy is the checker who released it. */
    public function postDraft(Journal $journal, ?string $approvedBy): Journal
    {
        $journal->forceFill([
            'number' => $this->numberer->next($journal->entity_id, $journal->book_id, $journal->posting_date),
            'status' => JournalStatus::Posted->value,
            'posted_at' => now(),
        ] + ($approvedBy === null ? [] : ['approved_by' => $approvedBy]))->save();
        $this->auditPosted($journal);

        return $journal;
    }

    private function auditPosted(Journal $journal): void
    {
        $actorId = $journal->getAttribute('approved_by') ?? $journal->getAttribute('created_by');
        $this->audit->record('journal.posted', AuditSubject::of('journal', $journal->id), null, [
            'number' => $journal->number, 'kind' => $journal->kind->value, 'status' => $journal->status->value,
            'posting_date' => $journal->posting_date->toDateString(), 'source_type' => $journal->source_type, 'source_id' => $journal->source_id,
        ], $journal->reason, actor: is_string($actorId) ? Actor::user($actorId) : null);
    }

    /** @return list<array<string, mixed>> one row per line, every row with the same columns */
    private function rows(Journal $journal, JournalDraft $draft): array
    {
        $tenantId = TenantContext::id();

        return array_map(fn (DraftLine $line): array => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'journal_id' => $journal->id, 'line_no' => $line->lineNo,
            'account_id' => $line->accountId, 'side' => $line->side->value, 'amount_minor' => $line->amountMinor,
            'currency' => $line->currency, 'base_amount_minor' => $line->baseAmountMinor,
            'role_code' => $line->roleCode, 'memo' => $line->memo,
        ] + $this->dimensionColumns($line->dimensions), $draft->lines);
    }

    /** @return array<string, mixed> */
    private function dimensionColumns(LineDimensions $dimensions): array
    {
        $columns = $dimensions->toColumns();
        $columns['dims_ext'] = $columns['dims_ext'] === null ? null : json_encode($columns['dims_ext'], JSON_THROW_ON_ERROR);

        return $columns;
    }
}
