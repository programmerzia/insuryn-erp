<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read side for the premium schedule (design §2.4 installments). */
final class InstallmentQuery
{
    /**
     * Installments due before $asOf with premium still unpaid, oldest first.
     *
     * @return list<array{installment_id: string, policy_id: string, policy_number: string|null, no: int, due_date: string, outstanding_minor: int, days_overdue: int}>
     */
    public function overdue(string $entityId, CarbonImmutable $asOf): array
    {
        $rows = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')
            ->where('p.entity_id', $entityId)->where('i.due_date', '<', $asOf->toDateString())
            ->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->orderBy('i.due_date')->orderBy('i.no')
            ->get(['i.id', 'i.policy_id', 'p.number', 'i.no', 'i.due_date', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);

        return array_values($rows->map(fn (object $row): array => [
            'installment_id' => (string) $row->id, 'policy_id' => (string) $row->policy_id, 'policy_number' => $row->number === null ? null : (string) $row->number,
            'no' => (int) $row->no, 'due_date' => (string) $row->due_date, 'outstanding_minor' => (int) $row->outstanding,
            'days_overdue' => (int) CarbonImmutable::parse((string) $row->due_date)->diffInDays($asOf),
        ])->all());
    }
}
