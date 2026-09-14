<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Finance\Payables\Domain\Models\ApBill;
use App\Modules\Finance\Payables\Domain\Models\ApBillLine;
use App\Modules\Finance\Payables\Domain\Models\PaymentRun;
use App\Modules\Finance\Payables\Domain\Models\PaymentRunItem;
use App\Modules\Finance\Payables\Domain\Models\Supplier;
use Carbon\CarbonImmutable;

/**
 * Accounting Event Mapper for payables (addendum v2 §B.4 Posting), called inside the source transaction. DECISION D-100: one event per bill and one per
 * payment run, with the bill lines and run items as `for_each` line groups.
 *
 * AP_BILL_POSTED: per line DR the line's expense account (net + VAT unless VAT is recoverable) and DR input VAT when recoverable; CR accounts payable
 * (payable), CR VAT deducted at source, CR tax deducted at source. AP_BILL_CANCELLED mirrors it. AP_PAYMENT_RELEASED: per item DR accounts payable,
 * CR the run's bank account total. Dimensions: branch, payee_party (the supplier's party) and ap_bill / payment_run.
 */
final class PayablesAccountingEvents
{
    public function __construct(
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
    ) {}

    public function billPosted(ApBill $bill, Supplier $supplier): void
    {
        $this->submitBill('AP_BILL_POSTED', $bill, $supplier, $bill->accounting_date ?? $bill->bill_date);
    }

    public function billCancelled(ApBill $bill, Supplier $supplier, CarbonImmutable $on): void
    {
        $this->submitBill('AP_BILL_CANCELLED', $bill, $supplier, $on);
    }

    /** @param iterable<PaymentRunItem> $items */
    public function paymentReleased(PaymentRun $run, iterable $items): void
    {
        $payloadItems = [];
        $branch = null;
        foreach ($items as $item) {
            $branch ??= $item->branch_id;
            $payloadItems[] = ['ap_bill_id' => $item->payable_id, 'payee_party_id' => $item->payee_party_id, 'branch_id' => $item->branch_id, 'amount' => $item->amount_minor];
        }
        ($this->submit)(
            entityId: $run->entity_id, eventType: 'AP_PAYMENT_RELEASED', sourceType: 'payment_run', sourceId: $run->id, idempotencyKey: 'AP_PAYMENT_RELEASED:'.$run->id,
            transactionDate: $run->pay_date, effectiveDate: $run->pay_date, currency: $run->currency,
            payload: ['payment_run_id' => $run->id, 'number' => $run->number, 'amount' => $run->total_minor, 'total' => $run->total_minor, 'items' => $payloadItems,
                'bank_account_id' => $run->bank_account_id,
                'account_overrides' => ['bank_main' => $this->bankAccounts->glAccountFor($run->bank_account_id, $run->entity_id, $run->currency)]],
            dimensions: ['branch' => $branch, 'payment_run' => $run->id],
        );
    }

    private function submitBill(string $eventType, ApBill $bill, Supplier $supplier, CarbonImmutable $on): void
    {
        $recoverable = (bool) config('erp.payables.input_vat_recoverable', false);
        $lines = $bill->lines()->get()->map(fn (ApBillLine $line): array => [
            'line_no' => $line->line_no, 'account_id' => $line->account_id, 'claim_id' => $line->claim_id, 'policy_id' => $line->policy_id,
            'expense' => $line->net_minor + ($recoverable ? 0 : $line->vat_minor), 'recoverable_vat' => $recoverable ? $line->vat_minor : 0,
        ])->values()->all();
        ($this->submit)(
            entityId: $bill->entity_id, eventType: $eventType, sourceType: 'ap_bill', sourceId: $bill->id, idempotencyKey: "{$eventType}:{$bill->id}",
            transactionDate: $on, effectiveDate: $on, currency: $bill->currency,
            payload: ['ap_bill_id' => $bill->id, 'number' => $bill->number, 'supplier_reference' => $bill->supplier_reference, 'gross' => $bill->gross_minor,
                'payable' => $bill->payable_minor, 'vds' => $bill->vds_minor, 'tds' => $bill->tds_minor, 'lines' => $lines],
            dimensions: ['branch' => $bill->branch_id, 'payee_party' => $supplier->party_id, 'ap_bill' => $bill->id],
        );
    }
}
