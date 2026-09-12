<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimStatus;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Insurance\Claims\Domain\Models\ClaimRecovery;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/**
 * Design §5.5 claim lifecycle except payments (ClaimPaymentService): register, reserve/adjust, close (releasing the unapproved reserve,
 * CLAIM_CLOSED — INVARIANT Σ claims_outstanding per claim = 0 after close), reject (releasing the reserve), reopen (approval), recovery.
 * Every change is audited on the claim with the permission exercised, which SoD (claim.reserve ✕ claim.approve) reads.
 */
final class ClaimService
{
    private const RECOVERY_TYPES = ['salvage', 'subrogation', 'third_party'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly ClaimReserveBook $reserves,
        private readonly ClaimAccountingEvents $accounting,
        private readonly ApprovalService $approvals,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation POLICY_NOT_ON_COVER | LOSS_OUTSIDE_COVER | REPORTED_BEFORE_LOSS */
    public function register(string $policyId, CarbonImmutable $lossDate, string $description, string $actorUserId, CarbonImmutable $reportedOn): Claim
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->permissions->authorize($actorUserId, 'claim.register', AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
        self::assertLossCovered($policy, $lossDate, $reportedOn);
        $number = $this->numbers->reserve(new DocumentNumberScope($policy->entity_id, $policy->branch_id, 'claim', 'CLM', $reportedOn), $actorUserId);

        return DB::transaction(function () use ($policy, $lossDate, $description, $actorUserId, $reportedOn, $number): Claim {
            $claim = Claim::query()->create(['entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'policy_id' => $policy->id, 'number' => $number->number,
                'loss_date' => $lossDate->toDateString(), 'reported_on' => $reportedOn->toDateString(), 'description' => $description,
                'status' => ClaimStatus::Registered->value, 'currency' => $policy->currency, 'created_by' => $actorUserId]);
            $this->numbers->markUsed($number->id, 'claim', $claim->id);
            $this->audit->record('claim.registered', AuditSubject::of('claim', $claim->id), null, ['number' => $claim->number, 'policy_id' => $policy->id],
                null, 'claim.register', Actor::user($actorUserId));

            return $claim;
        });
    }

    /**
     * Sets the case reserve to $reserveMinor (a total, design §4.6): the first reserve posts CLAIM_RESERVED, later ones CLAIM_RESERVE_ADJUSTED.
     *
     * @throws BusinessRuleViolation INVALID_AMOUNT | RESERVE_UNCHANGED | RESERVE_BELOW_APPROVED | INVALID_CLAIM_TRANSITION
     */
    public function reserve(string $claimId, int $reserveMinor, string $reason, string $actorUserId, CarbonImmutable $on): Claim
    {
        $this->authorize($claimId, 'claim.reserve', $actorUserId);
        if ($reserveMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A reserve must be a positive amount.');
        }

        return DB::transaction(function () use ($claimId, $reserveMinor, $reason, $actorUserId, $on): Claim {
            $claim = $this->lock($claimId, [ClaimStatus::Registered, ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], 'reserve');
            if ($reserveMinor === $claim->reserve_minor) {
                throw new BusinessRuleViolation('RESERVE_UNCHANGED', "Claim {$claim->number} is already reserved at {$reserveMinor}.");
            }
            $committed = $this->reserves->committedMinor($claim->id);
            if ($reserveMinor < $committed) {
                throw new BusinessRuleViolation('RESERVE_BELOW_APPROVED', "Claim {$claim->number} has {$committed} approved; the reserve cannot be lower.");
            }
            $before = $claim->reserve_minor;
            $this->reserves->record($claim, $reserveMinor, $claim->reserve_version === 0 ? 'reserve' : 'adjustment', $reason, $actorUserId, $on);
            if ($claim->status === ClaimStatus::Registered) {
                $claim->forceFill(['status' => ClaimStatus::Reserved->value])->save();
            }
            $this->audit->record('claim.reserved', AuditSubject::of('claim', $claim->id), ['reserve_minor' => $before], ['reserve_minor' => $reserveMinor],
                $reason, 'claim.reserve', Actor::user($actorUserId));

            return $claim;
        });
    }

    /** @throws BusinessRuleViolation PAYMENTS_OUTSTANDING | INVALID_CLAIM_TRANSITION */
    public function close(string $claimId, string $reason, string $actorUserId, CarbonImmutable $on): Claim
    {
        $this->authorize($claimId, 'claim.close', $actorUserId);

        return DB::transaction(function () use ($claimId, $reason, $actorUserId, $on): Claim {
            $claim = $this->lock($claimId, [ClaimStatus::Approved, ClaimStatus::Paid], 'close');
            if (ClaimPayment::query()->where('claim_id', $claim->id)->whereIn('status', ClaimPaymentStatus::unsettled())->exists()) {
                throw new BusinessRuleViolation('PAYMENTS_OUTSTANDING', "Claim {$claim->number} has payments not yet paid or rejected.");
            }
            $this->releaseUnapprovedReserve($claim, 'close_release', $reason, $actorUserId, $on);
            $claim->forceFill(['status' => ClaimStatus::Closed->value, 'closed_on' => $on->toDateString(), 'status_reason' => $reason])->save();
            $this->audit->record('claim.closed', AuditSubject::of('claim', $claim->id), null, ['reserve_minor' => $claim->reserve_minor], $reason, 'claim.close', Actor::user($actorUserId));

            return $claim;
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED | INVALID_CLAIM_TRANSITION */
    public function reject(string $claimId, string $reason, string $actorUserId, CarbonImmutable $on): Claim
    {
        self::assertReason($reason);
        $this->authorize($claimId, 'claim.approve', $actorUserId);

        return DB::transaction(function () use ($claimId, $reason, $actorUserId, $on): Claim {
            $claim = $this->lock($claimId, [ClaimStatus::Registered, ClaimStatus::Reserved], 'reject');
            if (ClaimPayment::query()->where('claim_id', $claim->id)->whereIn('status', ClaimPaymentStatus::unsettled())->exists()) {
                throw new BusinessRuleViolation('PAYMENTS_OUTSTANDING', "Claim {$claim->number} has payments not yet paid or rejected.");
            }
            $this->releaseUnapprovedReserve($claim, 'reject_release', $reason, $actorUserId, $on);
            $claim->forceFill(['status' => ClaimStatus::Rejected->value, 'status_reason' => $reason])->save();
            $this->audit->record('claim.rejected', AuditSubject::of('claim', $claim->id), null, null, $reason, 'claim.approve', Actor::user($actorUserId));

            return $claim;
        });
    }

    /**
     * closed ─reopen(approval)─▶ reserved. An approval policy for `claim_reopen` makes it wait for approval (returns the approval id).
     *
     * @throws BusinessRuleViolation REASON_REQUIRED | INVALID_CLAIM_TRANSITION
     */
    public function reopen(string $claimId, string $reason, string $actorUserId, CarbonImmutable $on): ?string
    {
        self::assertReason($reason);
        $this->authorize($claimId, 'claim.approve', $actorUserId);

        return DB::transaction(function () use ($claimId, $reason, $actorUserId, $on): ?string {
            $claim = $this->lock($claimId, [ClaimStatus::Closed], 'reopen');
            $approvalId = $this->approvals->request('claim_reopen', $claim->id, new ApprovalFacts($claim->reserve_minor), $actorUserId, $on, ['reason' => $reason]);
            if ($approvalId === null) {
                $this->completeReopen($claim->id, $reason, $actorUserId);
            }

            return $approvalId;
        });
    }

    /** Called directly or by ClaimReopenApprovalHandler. */
    public function completeReopen(string $claimId, string $reason, string $approverId): void
    {
        $claim = $this->lock($claimId, [ClaimStatus::Closed], 'reopen');
        $claim->forceFill(['status' => ClaimStatus::Reserved->value, 'closed_on' => null, 'status_reason' => $reason])->save();
        $this->audit->record('claim.reopened', AuditSubject::of('claim', $claim->id), ['status' => 'closed'], ['status' => 'reserved'], $reason, 'claim.approve', Actor::user($approverId));
    }

    /**
     * Design §4.8 recovery in cash, any time after payment. Interpretation: recorded under claim.pay_request (claims manager handles claim money).
     *
     * @throws BusinessRuleViolation INVALID_AMOUNT | INVALID_RECOVERY_TYPE | CLAIM_NOT_PAID
     */
    public function recover(string $claimId, string $type, int $amountMinor, ?string $bankAccountId, ?string $reference, string $actorUserId, CarbonImmutable $receivedOn): ClaimRecovery
    {
        $this->authorize($claimId, 'claim.pay_request', $actorUserId);
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A recovery must be a positive amount.');
        }
        if (! in_array($type, self::RECOVERY_TYPES, true)) {
            throw new BusinessRuleViolation('INVALID_RECOVERY_TYPE', 'A recovery is salvage, subrogation or third_party.');
        }

        return DB::transaction(function () use ($claimId, $type, $amountMinor, $bankAccountId, $reference, $actorUserId, $receivedOn): ClaimRecovery {
            $claim = Claim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if (! in_array($claim->status, [ClaimStatus::Paid, ClaimStatus::Closed], true)) {
                throw new BusinessRuleViolation('CLAIM_NOT_PAID', "Claim {$claim->number} has not been paid; recoveries come after payment.");
            }
            $recovery = ClaimRecovery::query()->create(['claim_id' => $claim->id, 'type' => $type, 'amount_minor' => $amountMinor, 'currency' => $claim->currency,
                'received_on' => $receivedOn->toDateString(), 'bank_account_id' => $bankAccountId, 'reference' => $reference, 'recorded_by' => $actorUserId]);
            $this->accounting->recovered($claim, $recovery);
            $this->audit->record('claim.recovered', AuditSubject::of('claim', $claim->id), null, ['recovery_id' => $recovery->id, 'type' => $type, 'amount_minor' => $amountMinor],
                null, 'claim.pay_request', Actor::user($actorUserId));

            return $recovery;
        });
    }

    private function releaseUnapprovedReserve(Claim $claim, string $kind, string $reason, string $actorUserId, CarbonImmutable $on): void
    {
        $approved = $this->reserves->approvedMinor($claim->id);
        if ($claim->reserve_minor > $approved) {
            $this->reserves->record($claim, $approved, $kind, $reason, $actorUserId, $on);
        }
    }

    private static function assertLossCovered(Policy $policy, CarbonImmutable $lossDate, CarbonImmutable $reportedOn): void
    {
        if (in_array($policy->status, [PolicyStatus::Quote], true)) {
            throw new BusinessRuleViolation('POLICY_NOT_ON_COVER', "Policy {$policy->id} was never issued.");
        }
        $coverEnds = $policy->cancel_date?->subDay() ?? $policy->expiry;
        if ($lossDate->lessThan($policy->inception) || $lossDate->greaterThan($coverEnds)) {
            throw new BusinessRuleViolation('LOSS_OUTSIDE_COVER', "The loss on {$lossDate->toDateString()} is outside cover {$policy->inception->toDateString()} – {$coverEnds->toDateString()}.");
        }
        if ($reportedOn->lessThan($lossDate)) {
            throw new BusinessRuleViolation('REPORTED_BEFORE_LOSS', 'A claim cannot be reported before the loss.');
        }
    }

    private static function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'This claim decision needs a reason.');
        }
    }

    private function authorize(string $claimId, string $permission, string $actorUserId): void
    {
        $claim = Claim::query()->findOrFail($claimId);
        $this->permissions->authorize($actorUserId, $permission, AuthorizationScope::branch($claim->entity_id, $claim->branch_id));
    }

    /** @param list<ClaimStatus> $allowed */
    private function lock(string $claimId, array $allowed, string $action): Claim
    {
        $claim = Claim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
        if (! in_array($claim->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_CLAIM_TRANSITION', "A {$claim->status->value} claim cannot {$action}.");
        }

        return $claim;
    }
}
