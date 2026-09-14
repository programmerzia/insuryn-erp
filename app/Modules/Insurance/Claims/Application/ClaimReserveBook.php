<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Insurance\Claims\Domain\Models\ClaimReserve;
use Carbon\CarbonImmutable;

/**
 * The claim's reserve history (design §4.6 "Reserve history = ordered adjustments"): every change appends a version with the new total and
 * the delta, updates the claim and posts the matching event. Callers hold the claim's row lock and run inside a transaction.
 */
final class ClaimReserveBook
{
    public function __construct(private readonly ClaimAccountingEvents $accounting) {}

    public function record(Claim $claim, int $newReserveMinor, string $kind, string $reason, string $actorUserId, CarbonImmutable $on): ClaimReserve
    {
        $reserve = ClaimReserve::query()->create([
            'claim_id' => $claim->id, 'version' => $claim->reserve_version + 1, 'reserve_minor' => $newReserveMinor,
            'delta_minor' => $newReserveMinor - $claim->reserve_minor, 'kind' => $kind, 'reason' => $reason,
            'recorded_on' => $on->toDateString(), 'recorded_by' => $actorUserId,
        ]);
        $claim->forceFill(['reserve_minor' => $newReserveMinor, 'reserve_version' => $reserve->version])->save();
        $this->accounting->reserveChanged($claim, $reserve);

        return $reserve;
    }

    /** Amount of the reserve already committed to payments (approved, awaiting approval, or paid). */
    public function committedMinor(string $claimId): int
    {
        return (int) ClaimPayment::query()->where('claim_id', $claimId)->whereIn('status', ClaimPaymentStatus::committed())->sum('amount_minor');
    }

    /** Amount paid on the claim over its whole life (CLAIM_PAID). */
    public function paidMinor(string $claimId): int
    {
        return (int) ClaimPayment::query()->where('claim_id', $claimId)->where('status', ClaimPaymentStatus::Paid->value)->sum('amount_minor');
    }

    /** Amount that has passed CLAIM_APPROVED, i.e. moved out of claims_outstanding. */
    public function approvedMinor(string $claimId): int
    {
        return (int) ClaimPayment::query()->where('claim_id', $claimId)->whereIn('status', ClaimPaymentStatus::approved())->sum('amount_minor');
    }
}
