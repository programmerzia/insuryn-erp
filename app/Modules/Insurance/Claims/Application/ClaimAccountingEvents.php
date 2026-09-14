<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Insurance\Claims\Domain\Models\ClaimRecovery;
use App\Modules\Insurance\Claims\Domain\Models\ClaimReserve;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;

/** Accounting Event Mapper for claims (design §4.6–§4.8), called inside the source transaction. Dimensions: the policy's plus the claim. */
final class ClaimAccountingEvents
{
    public function __construct(
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
    ) {}

    /** Design §4.6: the first reserve is CLAIM_RESERVED, later changes CLAIM_RESERVE_ADJUSTED (delta, negative decreases), a close releases with CLAIM_CLOSED. */
    public function reserveChanged(Claim $claim, ClaimReserve $reserve): void
    {
        [$eventType, $payload] = match (true) {
            $reserve->kind === 'reserve' && $reserve->version === 1 => ['CLAIM_RESERVED', ['amount' => $reserve->delta_minor]],
            $reserve->kind === 'close_release' => ['CLAIM_CLOSED', ['release' => -$reserve->delta_minor]],
            default => ['CLAIM_RESERVE_ADJUSTED', ['delta' => $reserve->delta_minor]],
        };
        $this->submitFor($claim, $eventType, 'claim_reserve', $reserve->id, "{$eventType}:{$claim->id}:{$reserve->version}", $reserve->recorded_on,
            $payload + ['claim_id' => $claim->id, 'reserve_version' => $reserve->version]);
        // Reinsurance MVP: reinsurers' share of the reserve change follows.
        \Illuminate\Support\Facades\Event::dispatch(new \App\Modules\Insurance\Claims\Domain\Events\ClaimReserveChanged($claim->id, $reserve->id, $reserve->delta_minor, $reserve->recorded_on->toDateString()));
    }

    /** Design §4.7: DR claims_outstanding / CR claims_payable. */
    public function approved(Claim $claim, ClaimPayment $payment): void
    {
        $this->submitFor($claim, 'CLAIM_APPROVED', 'claim_payment', $payment->id, 'CLAIM_APPROVED:'.$payment->id, $payment->approved_on, $this->approvedPayload($payment));
    }

    /**
     * Design §4.7: DR claims_payable / CR bank_main (the payment's bank account's GL account when given).
     */
    public function paid(Claim $claim, ClaimPayment $payment, CarbonImmutable $paidOn): void
    {
        $this->submitFor($claim, 'CLAIM_PAID', 'claim_payment', $payment->id, 'CLAIM_PAID:'.$payment->id, $paidOn, $this->paidPayload($claim, $payment));
        // Reinsurance MVP: reinsurers' share of the payment becomes recoverable.
        \Illuminate\Support\Facades\Event::dispatch(new \App\Modules\Insurance\Claims\Domain\Events\ClaimPaid($claim->id, $payment->id, $payment->amount_minor, $paidOn->toDateString()));
    }

    /**
     * Gap fixes W7 (GA-04): the CLAIM_APPROVED payload, also for the approver's preview of the lines.
     *
     * @return array<string, mixed>
     */
    public function approvedPayload(ClaimPayment $payment): array
    {
        return ['amount' => $payment->amount_minor, 'claim_payment_id' => $payment->id];
    }

    /**
     * Gap fixes W7 (GA-04): the CLAIM_PAID payload, also for the approver's preview of the lines.
     *
     * @return array<string, mixed>
     */
    public function paidPayload(Claim $claim, ClaimPayment $payment): array
    {
        return ['amount' => $payment->amount_minor, 'claim_payment_id' => $payment->id, 'bank_account_id' => $payment->bank_account_id]
            + $this->bankOverride($payment->bank_account_id, $claim);
    }

    /**
     * Gap fixes W7 (GA-04): the dimensions every claim event carries — the policy's plus the claim.
     *
     * @return array<string, string>
     */
    public static function dimensions(Claim $claim): array
    {
        return PolicyAccountingEvents::dimensions(Policy::query()->findOrFail($claim->policy_id)) + ['claim' => $claim->id];
    }

    /** Design §4.8: DR bank_main / CR claims_recovery_income. */
    public function recovered(Claim $claim, ClaimRecovery $recovery): void
    {
        $this->submitFor($claim, 'CLAIM_RECOVERED', 'claim_recovery', $recovery->id, 'CLAIM_RECOVERED:'.$recovery->id, $recovery->received_on,
            ['amount' => $recovery->amount_minor, 'recovery_id' => $recovery->id, 'recovery_type' => $recovery->type, 'reference' => $recovery->reference,
                'bank_account_id' => $recovery->bank_account_id] + ($recovery->number === null ? [] : ['receipt_number' => $recovery->number]) // GA-21: the bank line names the recovery receipt
                + $this->bankOverride($recovery->bank_account_id, $claim));
    }

    /** @return array{account_overrides?: array{bank_main: string}} */
    private function bankOverride(?string $bankAccountId, Claim $claim): array
    {
        return $bankAccountId === null ? [] : ['account_overrides' => ['bank_main' => $this->bankAccounts->glAccountFor($bankAccountId, $claim->entity_id, $claim->currency)]];
    }

    /** @param array<string, mixed> $payload */
    private function submitFor(Claim $claim, string $eventType, string $sourceType, string $sourceId, string $key, CarbonImmutable $date, array $payload): void
    {
        ($this->submit)(
            entityId: $claim->entity_id, eventType: $eventType, sourceType: $sourceType, sourceId: $sourceId, idempotencyKey: $key,
            transactionDate: $date, effectiveDate: $date, currency: $claim->currency, payload: $payload,
            dimensions: self::dimensions($claim),
        );
    }
}
