<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agent commission statement (design §6 report "commission statement"): entries dated in the range with totals, and the payable
 * (Σ amount − withholding of entries not yet paid) before and at the end of the range.
 */
final class CommissionStatementQuery
{
    /**
     * @return array{agent_id: string, from: string, to: string, opening_payable_minor: int, closing_payable_minor: int,
     *     entries: list<array{id: string, earned_on: string, kind: string, policy_id: string, policy_number: string|null, base_minor: int, rate_bp: int|null,
     *     amount_minor: int, withholding_minor: int, net_minor: int, status: string}>,
     *     totals: array{earned_minor: int, clawback_minor: int, withholding_minor: int, net_minor: int}}
     */
    public function statement(string $agentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('commission_entries as c')->leftJoin('policies as p', 'p.id', '=', 'c.policy_id')
            ->where('c.agent_id', $agentId)->whereBetween('c.earned_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('c.earned_on')->orderBy('c.id')
            ->get(['c.id', 'c.earned_on', 'c.kind', 'c.policy_id', 'p.number', 'c.base_minor', 'c.rate_bp', 'c.amount_minor', 'c.withholding_minor', 'c.status']);

        $entries = [];
        $totals = ['earned_minor' => 0, 'clawback_minor' => 0, 'withholding_minor' => 0, 'net_minor' => 0];
        foreach ($rows as $row) {
            $amount = (int) $row->amount_minor;
            $withholding = (int) $row->withholding_minor;
            $entries[] = ['id' => (string) $row->id, 'earned_on' => (string) $row->earned_on, 'kind' => (string) $row->kind, 'policy_id' => (string) $row->policy_id,
                'policy_number' => $row->number === null ? null : (string) $row->number, 'base_minor' => (int) $row->base_minor,
                'rate_bp' => $row->rate_bp === null ? null : (int) $row->rate_bp, 'amount_minor' => $amount, 'withholding_minor' => $withholding,
                'net_minor' => $amount - $withholding, 'status' => (string) $row->status];
            $totals[$row->kind === 'clawback' ? 'clawback_minor' : 'earned_minor'] += $amount;
            $totals['withholding_minor'] += $withholding;
            $totals['net_minor'] += $amount - $withholding;
        }

        return ['agent_id' => $agentId, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'opening_payable_minor' => $this->payable($agentId, fn (Builder $q) => $q->where('earned_on', '<', $from->toDateString())),
            'closing_payable_minor' => $this->payable($agentId, fn (Builder $q) => $q->where('earned_on', '<=', $to->toDateString())),
            'entries' => $entries, 'totals' => $totals];
    }

    /** @param callable(Builder): Builder $dated */
    private function payable(string $agentId, callable $dated): int
    {
        return (int) $dated(DB::table('commission_entries')->where('agent_id', $agentId)->where('status', '<>', 'paid'))
            ->selectRaw('coalesce(sum(amount_minor - withholding_minor), 0) as payable')->value('payable');
    }
}
