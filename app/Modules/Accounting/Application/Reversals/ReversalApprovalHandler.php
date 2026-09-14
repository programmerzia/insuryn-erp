<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reversals;

use App\Modules\Accounting\Application\Queries\JournalLinesPreview;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use App\Modules\Platform\Approvals\PreviewsApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes reversal request approvals (object type `journal_reversal`). */
final class ReversalApprovalHandler implements ApprovalHandler, DescribesApprovalSubject, PreviewsApprovalSubject
{
    public function __construct(private readonly ReversalRequestService $requests) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->requests->execute($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->requests->markRejected($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $journalId = (string) DB::table('journal_reversal_requests')->where('id', $objectId)->value('journal_id');
        $journal = DB::table('journals')->where('id', $journalId)->first(['number', 'currency']);

        return ['title' => 'Reverse journal '.(string) ($journal->number ?? ''), 'amount_minor' => (int) DB::table('journal_lines')->where('journal_id', $journalId)->where('side', 'debit')->sum('amount_minor'),
            'currency' => $journal === null ? null : (string) $journal->currency, 'link' => "/accounting/journals/{$journalId}"];
    }

    /** Gap fix GA-04: the reversal the final approval posts — the original's lines mirrored, on the requested date — with the original and the reason. */
    public function preview(string $objectId): array
    {
        $request = DB::table('journal_reversal_requests')->where('id', $objectId)->first(['journal_id', 'on_date', 'reason']);
        if ($request === null) {
            return ['link_label' => 'Open the journal to reverse', 'details' => [], 'lines' => [], 'posts_on_final_step' => true];
        }
        $journal = DB::table('journals')->where('id', (string) $request->journal_id)->first(['number', 'description']);

        return ['link_label' => 'Open the journal to reverse', 'details' => [['label' => 'Reversal date', 'value' => (string) $request->on_date, 'date' => true],
            ['label' => 'Reverses', 'value' => trim(((string) ($journal->number ?? '')).' '.((string) ($journal->description ?? '')))], ['label' => 'Reason', 'value' => (string) $request->reason]],
            'lines' => JournalLinesPreview::lines((string) $request->journal_id, mirrored: true), 'posts_on_final_step' => true];
    }
}
