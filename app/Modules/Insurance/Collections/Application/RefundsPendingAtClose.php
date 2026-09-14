<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocuments;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Support\Facades\DB;

/**
 * Slice 2.1b (D-55, CQ-C4): premium refunds of the period's entity requested and not yet released or rejected. A refund carries no business date
 * until it is paid, so it is dated by the day it was requested on the company's clock (requested_at in the entity's time zone). ASSUMPTION A-153.
 */
final class RefundsPendingAtClose implements PendingCloseDocuments
{
    public function __construct(private readonly BusinessClock $clock) {}

    public function pendingIn(FiscalPeriodView $period): array
    {
        $zone = $this->clock->timezone($period->entityId);
        $rows = DB::table('refunds as r')->join('policies as p', 'p.id', '=', 'r.policy_id')->where('r.entity_id', $period->entityId)->where('r.status', 'requested')
            ->whereRaw('(r.requested_at at time zone ?)::date between ? and ?', [$zone, $period->starts->toDateString(), $period->ends->toDateString()])
            ->orderBy('r.requested_at')->orderBy('r.id')
            ->select(['r.id', 'r.amount_minor', 'r.currency', 'p.number'])->selectRaw("to_char(r.requested_at at time zone ?, 'YYYY-MM-DD') as dated", [$zone])->get();

        return array_values($rows->map(fn (object $r): PendingCloseDocument => new PendingCloseDocument('refund', (string) $r->id, 'Refund on policy '.(string) ($r->number ?? ''),
            (string) $r->dated, 'requested', 'Release (pay) or reject the refund.', (int) $r->amount_minor, (string) $r->currency, '/refunds'))->all());
    }
}
