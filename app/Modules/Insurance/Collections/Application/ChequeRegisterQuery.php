<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Cheque register (spec §4): cheques received in a date range, presented or bounced, with totals. */
final class ChequeRegisterQuery
{
    /**
     * @return array{entity_id: string, from: string, to: string, totals: array{presented_minor: int, bounced_minor: int},
     *     rows: list<array{receipt_id: string, receipt_number: string, cheque_no: string, cheque_bank: string, cheque_date: string, value_date: string,
     *     amount_minor: int, state: string, bounced_on: string|null, bounce_reason: string|null}>}
     */
    public function register(string $entityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cheques = DB::table('receipts')->where('entity_id', $entityId)->where('channel', 'cheque')
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])->orderBy('value_date')->orderBy('number')
            ->get(['id', 'number', 'cheque_no', 'cheque_bank', 'cheque_date', 'value_date', 'amount_minor', 'status', 'bounced_on', 'bounce_reason']);

        $rows = [];
        $totals = ['presented_minor' => 0, 'bounced_minor' => 0];
        foreach ($cheques as $cheque) {
            $state = $cheque->status === 'bounced' ? 'bounced' : 'presented';
            $totals[$state.'_minor'] += (int) $cheque->amount_minor;
            $rows[] = ['receipt_id' => (string) $cheque->id, 'receipt_number' => (string) $cheque->number, 'cheque_no' => (string) $cheque->cheque_no,
                'cheque_bank' => (string) $cheque->cheque_bank, 'cheque_date' => (string) $cheque->cheque_date, 'value_date' => (string) $cheque->value_date,
                'amount_minor' => (int) $cheque->amount_minor, 'state' => $state, 'bounced_on' => $cheque->bounced_on === null ? null : (string) $cheque->bounced_on,
                'bounce_reason' => $cheque->bounce_reason === null ? null : (string) $cheque->bounce_reason];
        }

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'totals' => $totals, 'rows' => $rows];
    }
}
