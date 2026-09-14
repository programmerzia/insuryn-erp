<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Platform\Authorization\AreaReach;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cheque register (spec §4): cheques received in a date range with totals. Gap fix GA-14: a cheque is `in_clearing` (received into cheques in
 * clearing, not yet credited), `cleared` (credited by the bank on `cleared_on`), `bounced`, or `presented` (recorded before cheques went through clearing,
 * straight to the bank). `presented_minor` is every cheque not bounced, as before; `in_clearing_minor` and `cleared_minor` split it.
 */
final class ChequeRegisterQuery
{
    /**
     * @return array{entity_id: string, from: string, to: string, totals: array{presented_minor: int, bounced_minor: int, in_clearing_minor: int, cleared_minor: int},
     *     rows: list<array{receipt_id: string, receipt_number: string, cheque_no: string, cheque_bank: string, cheque_date: string, value_date: string,
     *     amount_minor: int, state: string, bounced_on: string|null, bounce_reason: string|null, cleared_on: string|null}>}
     */
    public function register(string $entityId, CarbonImmutable $from, CarbonImmutable $to, ?AreaReach $reach = null): array
    {
        // Follow-up H1: limited to the receipts within the user's reach when a screen passes it.
        $cheques = ($reach ?? AreaReach::everywhere())->constrain(DB::table('receipts'), 'entity_id', 'branch_id')->where('entity_id', $entityId)->where('channel', 'cheque')
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])->orderBy('value_date')->orderBy('number')
            ->get(['id', 'number', 'cheque_no', 'cheque_bank', 'cheque_date', 'value_date', 'amount_minor', 'status', 'bounced_on', 'bounce_reason', 'in_clearing', 'cleared_on']);

        $rows = [];
        $totals = ['presented_minor' => 0, 'bounced_minor' => 0, 'in_clearing_minor' => 0, 'cleared_minor' => 0];
        foreach ($cheques as $cheque) {
            $state = match (true) {
                $cheque->status === 'bounced' => 'bounced',
                $cheque->cleared_on !== null => 'cleared',
                (bool) $cheque->in_clearing => 'in_clearing',
                default => 'presented',
            };
            $amount = (int) $cheque->amount_minor;
            $totals[$state === 'bounced' ? 'bounced_minor' : 'presented_minor'] += $amount;
            if ($state === 'in_clearing' || $state === 'cleared') {
                $totals[$state.'_minor'] += $amount;
            }
            $rows[] = ['receipt_id' => (string) $cheque->id, 'receipt_number' => (string) $cheque->number, 'cheque_no' => (string) $cheque->cheque_no,
                'cheque_bank' => (string) $cheque->cheque_bank, 'cheque_date' => (string) $cheque->cheque_date, 'value_date' => (string) $cheque->value_date,
                'amount_minor' => $amount, 'state' => $state, 'bounced_on' => $cheque->bounced_on === null ? null : (string) $cheque->bounced_on,
                'bounce_reason' => $cheque->bounce_reason === null ? null : (string) $cheque->bounce_reason, 'cleared_on' => $cheque->cleared_on === null ? null : (string) $cheque->cleared_on];
        }

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'totals' => $totals, 'rows' => $rows];
    }
}
