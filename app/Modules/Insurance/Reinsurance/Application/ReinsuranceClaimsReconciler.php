<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Close task `ri_claims_reconciliation`: reinsurers' share of outstanding claims plus claims recoverable (the claim shares) against `ri_outstanding_claims` and
 * `ri_claims_recoverable`, per reinsurer. Money received from reinsurers entered without a reinsurer is outside the register (A-259).
 */
final class ReinsuranceClaimsReconciler implements AccountBalanceReconciler
{
    public function subledger(): string
    {
        return 'ri_claims';
    }

    public function itemDimension(): string
    {
        return 'reinsurer';
    }

    public function accountRoles(): array
    {
        return ['ri_outstanding_claims', 'ri_claims_recoverable'];
    }

    public function excludesUnattributedLines(): bool
    {
        return true;
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        return SubledgerItems::of('reinsurer', DB::table('ri_claim_shares as s')->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')->where('s.entity_id', $entityId)
            ->where('s.recorded_on', '<=', $asOf->toDateString())->groupBy('r.party_id')->orderBy('r.party_id')
            ->selectRaw("r.party_id as object_id, sum(case when s.kind = 'reserve' then s.amount_minor else s.amount_minor - s.from_reserve_minor end) as amount")->get());
    }
}
