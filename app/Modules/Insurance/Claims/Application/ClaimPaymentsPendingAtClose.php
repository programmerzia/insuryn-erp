<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocuments;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use Illuminate\Support\Facades\DB;

/**
 * Slice 2.1b (D-55, CQ-C4): claim payments of the period's entity that are not settled — waiting for approval, approved but not released, release
 * requested, or released and waiting for the release approval. Dated by the release date once released (it posts on that date), otherwise by the
 * approval date. ASSUMPTION A-153.
 */
final class ClaimPaymentsPendingAtClose implements PendingCloseDocuments
{
    private const CLEARED_BY = [
        'pending_approval' => 'Approve or reject the payment.',
        'approved' => 'Request the release and pay it, or reject it.',
        'release_requested' => 'Release (pay) it.',
        'release_pending_approval' => 'Approve or reject the release.',
    ];

    public function pendingIn(FiscalPeriodView $period): array
    {
        $rows = DB::table('claim_payments as cp')->join('claims as c', 'c.id', '=', 'cp.claim_id')->where('c.entity_id', $period->entityId)
            ->whereIn('cp.status', array_keys(self::CLEARED_BY))
            ->whereBetween(DB::raw('coalesce(cp.paid_on, cp.approved_on)'), [$period->starts->toDateString(), $period->ends->toDateString()])
            ->orderByRaw('coalesce(cp.paid_on, cp.approved_on)')->orderBy('cp.id')
            ->get(['cp.id', 'cp.claim_id', 'cp.status', 'cp.amount_minor', 'cp.currency', 'c.number', DB::raw('coalesce(cp.paid_on, cp.approved_on) as dated')]);

        return array_values($rows->map(fn (object $p): PendingCloseDocument => new PendingCloseDocument('claim_payment', (string) $p->id, 'Claim payment on '.(string) $p->number,
            substr((string) $p->dated, 0, 10), (string) $p->status, self::CLEARED_BY[(string) $p->status] ?? 'Settle it.', (int) $p->amount_minor, (string) $p->currency,
            "/claims/{$p->claim_id}"))->all());
    }
}
