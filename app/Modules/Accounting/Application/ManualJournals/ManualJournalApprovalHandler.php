<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes manual journal approvals (object type `journal`). */
final class ManualJournalApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
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
}
