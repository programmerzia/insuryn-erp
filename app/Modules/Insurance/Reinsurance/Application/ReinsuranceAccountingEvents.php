<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;

/**
 * Accounting Event Mapper for reinsurance, called inside the source transaction. Dimensions: the policy's (and the claim's) plus the reinsurer (party id), so
 * the reinsurer balances reconcile per reinsurer.
 *
 * - RI_PREMIUM_CEDED (per cession row): Dr ri_premium_ceded / Cr ri_payable for the ceded premium; Dr ri_payable / Cr ri_commission_income for the commission.
 * - RI_CLAIM_RESERVE_CEDED (per reserve change share): Dr ri_outstanding_claims / Cr claims_expense.
 * - RI_CLAIM_RECOVERABLE (per payment share): Dr ri_claims_recoverable; Cr ri_outstanding_claims for the part set up as the reinsurer's reserve share, Cr claims_expense for the rest.
 * - RI_UPR_ADJUSTED (per period, branch and reinsurer): Dr ri_unearned_premium / Cr ri_premium_ceded for the change in the reinsurers' share of unearned premium.
 * A negative amount flips its lines (the engine does this).
 */
final class ReinsuranceAccountingEvents
{
    public function __construct(private readonly SubmitAccountingEvent $submit) {}

    public function premiumCeded(Policy $policy, string $cessionId, string $reinsurerPartyId, int $premiumMinor, int $commissionMinor, CarbonImmutable $on): void
    {
        ($this->submit)(
            entityId: $policy->entity_id, eventType: 'RI_PREMIUM_CEDED', sourceType: 'ri_cession', sourceId: $cessionId, idempotencyKey: 'RI_PREMIUM_CEDED:'.$cessionId,
            transactionDate: $on, effectiveDate: $on, currency: $policy->currency,
            payload: ['premium' => $premiumMinor, 'commission' => $commissionMinor, 'cession_id' => $cessionId],
            dimensions: PolicyAccountingEvents::dimensions($policy) + ['reinsurer' => $reinsurerPartyId],
        );
    }

    public function claimReserveCeded(Policy $policy, string $claimId, string $shareId, string $reinsurerPartyId, int $amountMinor, CarbonImmutable $on): void
    {
        ($this->submit)(
            entityId: $policy->entity_id, eventType: 'RI_CLAIM_RESERVE_CEDED', sourceType: 'ri_claim_share', sourceId: $shareId, idempotencyKey: 'RI_CLAIM_RESERVE_CEDED:'.$shareId,
            transactionDate: $on, effectiveDate: $on, currency: $policy->currency, payload: ['amount' => $amountMinor, 'share_id' => $shareId],
            dimensions: PolicyAccountingEvents::dimensions($policy) + ['claim' => $claimId, 'reinsurer' => $reinsurerPartyId],
        );
    }

    public function claimRecoverable(Policy $policy, string $claimId, string $shareId, string $reinsurerPartyId, int $fromReserveMinor, int $directMinor, CarbonImmutable $on): void
    {
        ($this->submit)(
            entityId: $policy->entity_id, eventType: 'RI_CLAIM_RECOVERABLE', sourceType: 'ri_claim_share', sourceId: $shareId, idempotencyKey: 'RI_CLAIM_RECOVERABLE:'.$shareId,
            transactionDate: $on, effectiveDate: $on, currency: $policy->currency,
            payload: ['amount' => $fromReserveMinor + $directMinor, 'from_reserve' => $fromReserveMinor, 'direct' => $directMinor, 'share_id' => $shareId],
            dimensions: PolicyAccountingEvents::dimensions($policy) + ['claim' => $claimId, 'reinsurer' => $reinsurerPartyId],
        );
    }

    public function unearnedAdjusted(string $entityId, string $adjustmentId, string $branchId, string $reinsurerPartyId, int $deltaMinor, string $currency, CarbonImmutable $on): void
    {
        ($this->submit)(
            entityId: $entityId, eventType: 'RI_UPR_ADJUSTED', sourceType: 'ri_upr_adjustment', sourceId: $adjustmentId, idempotencyKey: 'RI_UPR_ADJUSTED:'.$adjustmentId,
            transactionDate: $on, effectiveDate: $on, currency: $currency, payload: ['delta' => $deltaMinor, 'adjustment_id' => $adjustmentId],
            dimensions: ['branch' => $branchId, 'reinsurer' => $reinsurerPartyId],
        );
    }
}
