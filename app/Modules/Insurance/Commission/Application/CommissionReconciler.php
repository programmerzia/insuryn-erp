<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.1 commission subledger → commission_payable: per agent, Σ (amount − withholding) of entries dated on or before the date and
 * not paid. Withholding is owed to the tax authority (commission_withholding_payable), not to the agent.
 */
final class CommissionReconciler implements SubledgerReconciler
{
    public function subledger(): string
    {
        return 'commission';
    }

    public function itemDimension(): string
    {
        return 'agent';
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        return SubledgerItems::of('agent', DB::table('commission_entries')->where('entity_id', $entityId)->where('earned_on', '<=', $asOf->toDateString())
            ->where('status', '<>', 'paid')->groupBy('agent_id')->orderBy('agent_id')
            ->selectRaw('agent_id as object_id, sum(amount_minor - withholding_minor) as amount')->get());
    }
}
