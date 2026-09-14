<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\ReceiptAllocation;
use App\Modules\Insurance\Collections\Domain\Models\Refund;
use App\Modules\Insurance\Collections\Domain\Models\SuspenseItem;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;

/**
 * Accounting Event Mapper for collections (design §3.1, §8.2), called inside the source transaction. Payloads carry the
 * receipt number and reference so bank reconciliation can match the posted cash, and — when a bank account is known — point
 * bank_main at that account's GL account (§4.2).
 */
final class CollectionsAccountingEvents
{
    public function __construct(
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
    ) {}

    /**
     * Design §4.2: DR bank_main / CR premium_receivable, one event per allocation. Cash an agent collected posts AGENT_CASH_COLLECTED instead:
     * DR agent_receivable (dimension agent = the collecting agent) until the agent deposits it (spec §4).
     */
    public function premiumReceived(Receipt $receipt, ReceiptAllocation $allocation, Policy $policy): void
    {
        // GA-38 (A-194): only cash is held by the collecting agent; a cheque or mobile-money payment an agent collected goes to the bank like any receipt.
        $agentHoldsCash = $receipt->collected_by_agent_id !== null && $receipt->channel === 'cash';
        $eventType = $agentHoldsCash ? 'AGENT_CASH_COLLECTED' : 'PREMIUM_RECEIVED';
        $dimensions = PolicyAccountingEvents::dimensions($policy) + ['receipt' => $receipt->id];
        if ($agentHoldsCash) {
            $dimensions['agent'] = $receipt->collected_by_agent_id;
        }
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: $eventType, sourceType: 'receipt_allocation', sourceId: $allocation->id,
            idempotencyKey: $eventType.':'.$allocation->id, transactionDate: $receipt->value_date, effectiveDate: $receipt->value_date,
            currency: $receipt->currency, payload: $this->receiptPayload($receipt, $allocation->amount_minor) + ['receipt_allocation_id' => $allocation->id],
            dimensions: $dimensions,
        );
    }

    /** Design §4.9 first event: DR bank_main / CR suspense_receipts for the unallocated part. */
    public function receiptRecorded(Receipt $receipt, SuspenseItem $item): void
    {
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: 'RECEIPT_RECORDED', sourceType: 'receipt', sourceId: $receipt->id,
            idempotencyKey: 'RECEIPT_RECORDED:'.$receipt->id, transactionDate: $receipt->value_date, effectiveDate: $receipt->value_date,
            currency: $receipt->currency, payload: $this->receiptPayload($receipt, $item->amount_minor) + ['suspense_item_id' => $item->id],
            dimensions: ['branch' => $receipt->branch_id, 'receipt' => $receipt->id],
        );
    }

    /** Design §4.9 second event: DR suspense_receipts / CR premium_receivable. */
    public function receiptAllocated(Receipt $receipt, ReceiptAllocation $allocation, Policy $policy, CarbonImmutable $allocatedOn): void
    {
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: 'RECEIPT_ALLOCATED', sourceType: 'receipt_allocation', sourceId: $allocation->id,
            idempotencyKey: 'RECEIPT_ALLOCATED:'.$allocation->id, transactionDate: $allocatedOn, effectiveDate: $allocatedOn,
            currency: $receipt->currency, payload: $this->receiptPayload($receipt, $allocation->amount_minor) + ['receipt_allocation_id' => $allocation->id],
            dimensions: PolicyAccountingEvents::dimensions($policy) + ['receipt' => $receipt->id],
        );
    }

    /** Bounced cheque: an allocation's money never arrived — DR premium_receivable / CR bank_main (direct) or CR suspense_receipts (out of suspense). */
    public function allocationReversed(Receipt $receipt, ReceiptAllocation $allocation, Policy $policy, CarbonImmutable $reversedOn): void
    {
        $eventType = $allocation->suspense_item_id === null ? 'PREMIUM_RECEIPT_REVERSED' : 'RECEIPT_ALLOCATION_REVERSED';
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: $eventType, sourceType: 'receipt_allocation', sourceId: $allocation->id,
            idempotencyKey: $eventType.':'.$allocation->id, transactionDate: $reversedOn, effectiveDate: $reversedOn,
            currency: $receipt->currency, payload: $this->receiptPayload($receipt, $allocation->amount_minor) + ['receipt_allocation_id' => $allocation->id],
            dimensions: PolicyAccountingEvents::dimensions($policy) + ['receipt' => $receipt->id],
        );
    }

    /** Bounced cheque: the receipt's suspense leaves with the money — DR suspense_receipts / CR bank_main for the suspense item's full amount. */
    public function receiptBounced(Receipt $receipt, SuspenseItem $item, CarbonImmutable $bouncedOn): void
    {
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: 'RECEIPT_BOUNCED', sourceType: 'receipt', sourceId: $receipt->id,
            idempotencyKey: 'RECEIPT_BOUNCED:'.$receipt->id, transactionDate: $bouncedOn, effectiveDate: $bouncedOn,
            currency: $receipt->currency, payload: $this->receiptPayload($receipt, $item->amount_minor) + ['suspense_item_id' => $item->id],
            dimensions: ['branch' => $receipt->branch_id, 'receipt' => $receipt->id],
        );
    }

    /** Gap fixes W7 (GA-24): DR premium_written_off / CR premium_receivable for the small premium a cancelled policy still owed. */
    public function premiumWrittenOff(\App\Modules\Insurance\Collections\Domain\Models\PremiumWriteOff $writeOff, Policy $policy, CarbonImmutable $on): void
    {
        ($this->submit)(
            entityId: $policy->entity_id, eventType: 'PREMIUM_WRITTEN_OFF', sourceType: 'premium_write_off', sourceId: $writeOff->id,
            idempotencyKey: 'PREMIUM_WRITTEN_OFF:'.$writeOff->id, transactionDate: $on, effectiveDate: $on, currency: $policy->currency,
            payload: self::writeOffPayload($writeOff), dimensions: PolicyAccountingEvents::dimensions($policy),
        );
    }

    /** @return array{amount: int, write_off_id: string} the event payload, also for the approver's preview of the lines */
    public static function writeOffPayload(\App\Modules\Insurance\Collections\Domain\Models\PremiumWriteOff $writeOff): array
    {
        return ['amount' => (int) ($writeOff->written_off_minor ?? $writeOff->requested_minor), 'write_off_id' => $writeOff->id];
    }

    /** Design §4.4 event B: DR customer_refund_payable / CR bank_main. */
    public function refundIssued(Refund $refund, Policy $policy, CarbonImmutable $paidOn): void
    {
        ($this->submit)(
            entityId: $refund->entity_id, eventType: 'REFUND_ISSUED', sourceType: 'refund', sourceId: $refund->id,
            idempotencyKey: 'REFUND_ISSUED:'.$refund->id, transactionDate: $paidOn, effectiveDate: $paidOn,
            currency: $refund->currency, payload: ['amount' => $refund->amount_minor, 'refund_id' => $refund->id, 'bank_account_id' => $refund->bank_account_id]
                + $this->bankOverride($refund->bank_account_id, $refund->entity_id, $refund->currency),
            dimensions: PolicyAccountingEvents::dimensions($policy),
        );
    }

    /**
     * Gap fix GA-14: the bank credited a cheque that sat in clearing — DR bank_main (the receipt's bank account) / CR cheques_in_clearing for the whole
     * cheque, dated the clearing day. It carries the receipt number and reference, so the statement line matches it.
     */
    public function chequeCleared(Receipt $receipt, CarbonImmutable $clearedOn): void
    {
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: 'CHEQUE_CLEARED', sourceType: 'receipt', sourceId: $receipt->id,
            idempotencyKey: 'CHEQUE_CLEARED:'.$receipt->id, transactionDate: $clearedOn, effectiveDate: $clearedOn,
            currency: $receipt->currency, payload: ['amount' => $receipt->amount_minor, 'receipt_id' => $receipt->id, 'receipt_number' => $receipt->number,
                'reference' => $receipt->reference, 'bank_account_id' => $receipt->bank_account_id] + $this->bankOverride($receipt->bank_account_id, $receipt->entity_id, $receipt->currency),
            dimensions: ['branch' => $receipt->branch_id, 'receipt' => $receipt->id],
        );
    }

    /** Gap fix GA-14: the bank's charge for a returned cheque — DR bank_charges / CR bank_main (the receipt's bank account), dated the bounce. */
    public function chequeReturnCharged(Receipt $receipt, int $chargeMinor, CarbonImmutable $bouncedOn): void
    {
        ($this->submit)(
            entityId: $receipt->entity_id, eventType: 'CHEQUE_RETURN_CHARGED', sourceType: 'receipt', sourceId: $receipt->id,
            idempotencyKey: 'CHEQUE_RETURN_CHARGED:'.$receipt->id, transactionDate: $bouncedOn, effectiveDate: $bouncedOn,
            currency: $receipt->currency, payload: ['amount' => $chargeMinor, 'receipt_id' => $receipt->id, 'receipt_number' => $receipt->number,
                'reference' => $receipt->cheque_no === null ? $receipt->reference : "Returned cheque {$receipt->cheque_no}", 'bank_account_id' => $receipt->bank_account_id]
                + $this->bankOverride($receipt->bank_account_id, $receipt->entity_id, $receipt->currency),
            dimensions: ['branch' => $receipt->branch_id, 'receipt' => $receipt->id],
        );
    }

    /**
     * Gap fix GA-14: `in_clearing` = 1 while the cheque's money sits in cheques in clearing, so the bank lines of PREMIUM_RECEIVED, RECEIPT_RECORDED,
     * PREMIUM_RECEIPT_REVERSED and RECEIPT_BOUNCED (rule version 2) use cheques_in_clearing instead of bank_main; absent otherwise, which posts as version 1.
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(Receipt $receipt, int $amountMinor): array
    {
        return ['amount' => $amountMinor, 'receipt_id' => $receipt->id, 'receipt_number' => $receipt->number,
            'reference' => $receipt->reference, 'bank_account_id' => $receipt->bank_account_id]
            + ($receipt->stillInClearing() ? ['in_clearing' => 1] : [])
            + $this->bankOverride($receipt->bank_account_id, $receipt->entity_id, $receipt->currency);
    }

    /** @return array{account_overrides?: array{bank_main: string}} */
    private function bankOverride(?string $bankAccountId, string $entityId, string $currency): array
    {
        return $bankAccountId === null ? [] : ['account_overrides' => ['bank_main' => $this->bankAccounts->glAccountFor($bankAccountId, $entityId, $currency)]];
    }
}
