<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Close task `ri_balances_reconciliation`: the amounts due to reinsurers (cessions: premium ceded less commission) against `ri_payable`, per reinsurer. Lines
 * without a reinsurer (settlements to reinsurers entered as manual journals) are outside the register (A-259).
 */
final class ReinsurancePayableReconciler implements AccountBalanceReconciler
{
    public function subledger(): string
    {
        return 'ri_payable';
    }

    public function itemDimension(): string
    {
        return 'reinsurer';
    }

    public function accountRoles(): array
    {
        return ['ri_payable'];
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
        return SubledgerItems::of('reinsurer', DB::table('ri_cessions as c')->join('reinsurers as r', 'r.id', '=', 'c.reinsurer_id')->where('c.entity_id', $entityId)
            ->where('c.accounting_date', '<=', $asOf->toDateString())->groupBy('r.party_id')->orderBy('r.party_id')
            ->selectRaw('r.party_id as object_id, sum(c.premium_minor - c.commission_minor) as amount')->get());
    }
}
