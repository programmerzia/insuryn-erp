<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read models for payables reports (addendum v2 §B.4 Screens): the AP ageing (what is owed per bill at a date, by days past due) and a supplier
 * statement (bills, cancellations and payments in date order with the running balance). Both measure at a date the way PayablesReconciler does.
 */
final class PayablesQuery
{
    public const BUCKETS = ['current' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => 'Over 90 days'];

    /**
     * @return array{as_of: string, rows: list<array{bill_id: string, number: string|null, supplier_id: string, supplier: string, supplier_reference: string, bill_date: string, due_date: string,
     *     days_past_due: int, bucket: string, outstanding_minor: int}>, buckets: array<string, int>, by_supplier: list<array{supplier_id: string, supplier: string, buckets: array<string, int>, total_minor: int}>, total_minor: int}
     */
    public function ageing(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $paid = DB::table('payment_run_items as i')->join('payment_runs as r', 'r.id', '=', 'i.run_id')->where('i.status', 'released')->where('r.pay_date', '<=', $day)
            ->groupBy('i.payable_id')->selectRaw('i.payable_id, sum(i.amount_minor) as paid');
        $bills = DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->join('parties as p', 'p.id', '=', 's.party_id')
            ->leftJoinSub($paid, 'pd', 'pd.payable_id', '=', 'b.id')
            ->where('b.entity_id', $entityId)->whereNotNull('b.accounting_date')->where('b.accounting_date', '<=', $day)
            ->where(fn ($q) => $q->where('b.status', '<>', 'cancelled')->orWhere('b.cancelled_on', '>', $day))
            ->orderBy('p.display_name')->orderBy('b.due_date')
            ->get(['b.id', 'b.number', 'b.supplier_id', 'p.display_name', 'b.supplier_reference', 'b.bill_date', 'b.due_date', 'b.payable_minor', 'pd.paid']);

        $rows = [];
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $bySupplier = [];
        foreach ($bills as $bill) {
            $outstanding = (int) $bill->payable_minor - (int) ($bill->paid ?? 0);
            if ($outstanding <= 0) {
                continue;
            }
            $days = (int) CarbonImmutable::parse((string) $bill->due_date)->diffInDays($asOf, false);
            $bucket = match (true) { $days <= 0 => 'current', $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => 'over_90' };
            $rows[] = ['bill_id' => (string) $bill->id, 'number' => $bill->number === null ? null : (string) $bill->number, 'supplier_id' => (string) $bill->supplier_id,
                'supplier' => (string) $bill->display_name, 'supplier_reference' => (string) $bill->supplier_reference, 'bill_date' => (string) $bill->bill_date,
                'due_date' => (string) $bill->due_date, 'days_past_due' => max(0, $days), 'bucket' => $bucket, 'outstanding_minor' => $outstanding];
            $buckets[$bucket] += $outstanding;
            $bySupplier[(string) $bill->supplier_id] ??= ['supplier_id' => (string) $bill->supplier_id, 'supplier' => (string) $bill->display_name,
                'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0), 'total_minor' => 0];
            $bySupplier[(string) $bill->supplier_id]['buckets'][$bucket] += $outstanding;
            $bySupplier[(string) $bill->supplier_id]['total_minor'] += $outstanding;
        }

        return ['as_of' => $day, 'rows' => $rows, 'buckets' => $buckets, 'by_supplier' => array_values($bySupplier), 'total_minor' => array_sum($buckets)];
    }

    /**
     * @return array{opening_minor: int, closing_minor: int, lines: list<array{date: string, kind: string, document: string, reference: string, link: string|null, debit_minor: int, credit_minor: int, balance_minor: int}>}
     */
    public function statement(string $supplierId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $movements = [];
        foreach (DB::table('ap_bills')->where('supplier_id', $supplierId)->whereNotNull('accounting_date')->get(['id', 'number', 'supplier_reference', 'accounting_date', 'payable_minor', 'status', 'cancelled_on']) as $bill) {
            $movements[] = ['date' => (string) $bill->accounting_date, 'kind' => 'Bill', 'document' => (string) $bill->number, 'reference' => (string) $bill->supplier_reference,
                'link' => "/payables/bills/{$bill->id}", 'debit_minor' => 0, 'credit_minor' => (int) $bill->payable_minor];
            if ($bill->status === 'cancelled' && $bill->cancelled_on !== null) {
                $movements[] = ['date' => (string) $bill->cancelled_on, 'kind' => 'Bill cancelled', 'document' => (string) $bill->number, 'reference' => (string) $bill->supplier_reference,
                    'link' => "/payables/bills/{$bill->id}", 'debit_minor' => (int) $bill->payable_minor, 'credit_minor' => 0];
            }
        }
        $payments = DB::table('payment_run_items as i')->join('payment_runs as r', 'r.id', '=', 'i.run_id')->leftJoin('ap_bills as b', 'b.id', '=', 'i.payable_id')
            ->where('i.supplier_id', $supplierId)->where('i.status', 'released')->get(['r.id', 'r.number', 'r.pay_date', 'i.amount_minor', 'b.number as bill_number']);
        foreach ($payments as $payment) {
            $movements[] = ['date' => (string) $payment->pay_date, 'kind' => 'Payment', 'document' => (string) $payment->number, 'reference' => 'Bill '.(string) $payment->bill_number,
                'link' => "/payables/payment-runs/{$payment->id}", 'debit_minor' => (int) $payment->amount_minor, 'credit_minor' => 0];
        }
        usort($movements, fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'Payment' ? 1 : 0] <=> [$b['date'], $b['kind'] === 'Payment' ? 1 : 0]);

        $opening = 0;
        $balance = 0;
        $lines = [];
        foreach ($movements as $movement) {
            $delta = $movement['credit_minor'] - $movement['debit_minor'];
            if ($movement['date'] < $from->toDateString()) {
                $opening += $delta;
                $balance = $opening;
                continue;
            }
            if ($movement['date'] > $to->toDateString()) {
                continue;
            }
            $balance += $delta;
            $lines[] = [...$movement, 'balance_minor' => $balance];
        }

        return ['opening_minor' => $opening, 'closing_minor' => $balance, 'lines' => $lines];
    }
}
