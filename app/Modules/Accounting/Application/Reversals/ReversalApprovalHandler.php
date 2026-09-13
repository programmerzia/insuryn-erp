<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reversals;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes reversal request approvals (object type `journal_reversal`). */
final class ReversalApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
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
}
