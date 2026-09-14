<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Accounting\Application\Queries\JournalLinesPreview;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use App\Modules\Platform\Approvals\PreviewsApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes manual journal approvals (object type `journal`). */
final class ManualJournalApprovalHandler implements ApprovalHandler, DescribesApprovalSubject, PreviewsApprovalSubject
{
    public function __construct(private readonly ManualJournalService $journals) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->journals->postApproved($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->journals->cancel($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $journal = DB::table('journals')->where('id', $objectId)->first(['description', 'currency']);
        $amount = (int) DB::table('journal_lines')->where('journal_id', $objectId)->where('side', 'debit')->sum('amount_minor');

        return ['title' => 'Manual journal: '.(string) ($journal->description ?? ''), 'amount_minor' => $amount, 'currency' => $journal === null ? null : (string) $journal->currency,
            'link' => "/accounting/journals/{$objectId}"];
    }

    /** Gap fix GA-04: the lines the final approval posts, with the posting date, kind and reason. */
    public function preview(string $objectId): array
    {
        $journal = DB::table('journals')->where('id', $objectId)->first(['posting_date', 'kind', 'reason']);
        $details = $journal === null ? [] : [['label' => 'Posting date', 'value' => (string) $journal->posting_date, 'date' => true], ['label' => 'Kind', 'value' => ucfirst((string) $journal->kind)]];
        if ($journal !== null && trim((string) $journal->reason) !== '') {
            $details[] = ['label' => 'Reason', 'value' => (string) $journal->reason];
        }

        return ['link_label' => 'Open the journal draft', 'details' => $details, 'lines' => JournalLinesPreview::lines($objectId), 'posts_on_final_step' => true];
    }
}
