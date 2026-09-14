<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/**
 * Slice 2.1b (DECISION D-55, CQ-C4): documents dated in a period that still wait for an approval, a release or their posting, so the period
 * cannot receive them once it is locked. The close checklist lists them; a soft lock warns about them and the period lock refuses while any
 * remains (PERIOD_HAS_PENDING_DOCUMENTS). Implementations are container-tagged with this interface — the kernel lists its own (manual journals,
 * reversals, accounting events), business contexts theirs (claim payments, refunds) — so the kernel never names a business module.
 */
interface PendingCloseDocuments
{
    /** @return list<PendingCloseDocument> documents of the period's entity dated from its start to its end, still pending */
    public function pendingIn(FiscalPeriodView $period): array;
}
